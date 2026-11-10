import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useDebounce } from 'use-debounce';
import {
  aiTranslate,
  aiTranslateConfig,
  ApiError,
  basePath,
  discardConfigTranslation,
  discardDraft,
  fetchConfigTranslation,
  fetchTranslation,
  markConfigReviewed,
  markReviewed,
  publishConfigTranslation,
  saveConfigTranslation,
  saveDraft,
} from '../api.ts';
import { Box, Button, DropdownMenu, Flex, Heading, IconButton, Separator, Spinner, Text } from '@radix-ui/themes';
import {
  ArrowDownIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckCircledIcon,
  CheckIcon,
  DotsHorizontalIcon,
  ExclamationTriangleIcon,
  EyeOpenIcon,
  MagicWandIcon,
  PinBottomIcon,
  ReloadIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import type { AiTranslateItem, ConfigType, FieldRowData, Status, TranslationDoc } from '../types.ts';
import { statusMeta } from '../theme.ts';
import { PercentBar, StatusBadge } from './StatusBadge.tsx';
import { FieldRow } from './FieldRow.tsx';
import { preloadEditorFormats } from '../editorAssets.ts';
import { Banner } from './Banner.tsx';
import { SetupRequired } from './SetupRequired.tsx';

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

export default function PageEditor() {
  const params = useParams<{ id: string; langcode: string; type?: string }>();
  // Config items route as /config/:type/:id; content items as /page/:id.
  const isConfig = params.type !== undefined;
  const configType = (params.type ?? '') as ConfigType;
  const configId = params.id ?? '';
  const id = Number(params.id);
  const langcode = params.langcode ?? '';
  const navigate = useNavigate();

  const [doc, setDoc] = useState<TranslationDoc | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<Status>('not_translated');
  const [saveState, setSaveState] = useState<SaveState>('idle');
  const [publishing, setPublishing] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const [previewNonce, setPreviewNonce] = useState(0);
  const [error, setError] = useState<string | null>(null);
  // TRUE when the backend refused with `translation_not_enabled`: page
  // translation is blocked by site config, so show setup instructions instead
  // of the editor (see SetupRequired).
  const [setupRequired, setSetupRequired] = useState(false);
  // Which AI op is in flight: 'empty' | 'all' | a row key, or null when idle.
  const [aiBusy, setAiBusy] = useState<string | null>(null);
  // Guided review of source changes: whether we're stepping through changed
  // fields, and which changed keys the translator has acknowledged (client-only;
  // drives the "k of N reviewed" counter).
  const [reviewing, setReviewing] = useState(false);
  const [reviewedKeys, setReviewedKeys] = useState<Set<string>>(new Set());
  const [marking, setMarking] = useState(false);
  // The source changed in Canvas while this draft was open (set from a save
  // response — see the autosave effect).
  const [sourceConflict, setSourceConflict] = useState(false);
  // The source fingerprint this editor is working against. Sent on every save so
  // the server can flag a concurrent source edit; refreshed from each response.
  const sourceHashRef = useRef<string | undefined>(undefined);
  const dirtyRef = useRef(false);
  const previewRef = useRef<HTMLIFrameElement>(null);
  const [debouncedValues, debounceControl] = useDebounce(values, 800);

  // Draggable split between the field editor and the live preview.
  const splitRef = useRef<HTMLDivElement>(null);
  const draggingRef = useRef(false);
  const [previewWidth, setPreviewWidth] = useState(720);
  const onResizeMove = useCallback((e: React.PointerEvent<HTMLDivElement>) => {
    if (!draggingRef.current || !splitRef.current) return;
    const rect = splitRef.current.getBoundingClientRect();
    setPreviewWidth(Math.max(360, Math.min(rect.width - 380, rect.right - e.clientX)));
  }, []);
  const onResizeDown = useCallback((e: React.PointerEvent<HTMLDivElement>) => {
    e.preventDefault();
    draggingRef.current = true;
    e.currentTarget.setPointerCapture(e.pointerId);
  }, []);
  const onResizeUp = useCallback((e: React.PointerEvent<HTMLDivElement>) => {
    draggingRef.current = false;
    e.currentTarget.releasePointerCapture(e.pointerId);
  }, []);

  const load = useCallback(() => {
    dirtyRef.current = false;
    const request = isConfig
      ? fetchConfigTranslation(configType, configId, langcode)
      : fetchTranslation(id, langcode);
    return request
      .then((res) => {
        const seed: Record<string, string> = {};
        res.groups.forEach((g) => g.rows.forEach((r) => (seed[r.key] = r.target)));
        // Load the editors of every rich-text format on the page in one go,
        // before the fields mount and ask for them one by one.
        preloadEditorFormats(
          res.groups.flatMap((g) => g.rows.flatMap((r) => (r.format ? [r.format] : []))),
        );
        setValues(seed);
        setDoc(res);
        setStatus(res.status);
        setSaveState('idle');
        setError(null);
        // Reset the review/conflict state and the source basis for this doc.
        sourceHashRef.current = res.sourceHash;
        setReviewing(false);
        setReviewedKeys(new Set());
        setSourceConflict(false);
      })
      .catch((err: unknown) => {
        if (err instanceof ApiError) {
          const body = err.details as { code?: string } | null;
          if (body?.code === 'translation_not_enabled') {
            setSetupRequired(true);
            return;
          }
          setError(err.message);
          return;
        }
        setError(String(err));
      });
  }, [id, langcode, isConfig, configType, configId]);

  useEffect(() => {
    load();
  }, [load]);

  // Debounced autosave — only fires after the user actually edits. Both content
  // and config save into the module's own draft store (never Canvas auto-save);
  // for config the live override is written only on publish. The UX is identical.
  useEffect(() => {
    if (!dirtyRef.current) return;
    let cancelled = false;
    const request = isConfig
      ? saveConfigTranslation(configType, configId, langcode, debouncedValues, sourceHashRef.current)
      : saveDraft(id, langcode, debouncedValues, sourceHashRef.current);
    request
      .then((res) => {
        if (cancelled) return;
        dirtyRef.current = false;
        setStatus(res.status);
        setSaveState('saved');
        // Refresh the live preview to reflect the just-saved draft.
        setPreviewNonce((n) => n + 1);
        // Concurrent source edit: surface a non-destructive prompt and advance
        // the basis so the banner fires once per change, not every autosave.
        if (res.sourceHash) sourceHashRef.current = res.sourceHash;
        if (res.sourceChanged) setSourceConflict(true);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setSaveState('error');
        setError(err instanceof ApiError ? err.message : String(err));
      });
    return () => {
      cancelled = true;
    };
  }, [debouncedValues, id, langcode, isConfig, configType, configId]);

  // Refresh the live preview in place when a draft saves. Reloading the existing
  // iframe (rather than remounting it) and restoring the reader's scroll keeps
  // the preview still instead of flashing blank and jumping to the top.
  useEffect(() => {
    if (previewNonce === 0) return;
    const iframe = previewRef.current;
    const win = iframe?.contentWindow;
    if (!iframe || !win) return;
    let x = 0;
    let y = 0;
    try {
      x = win.scrollX;
      y = win.scrollY;
    } catch {
      // Preview not yet same-origin-loaded; nothing to preserve.
    }
    const onLoad = () => {
      try {
        iframe.contentWindow?.scrollTo(x, y);
      } catch {
        // Ignore: cross-origin or unloaded.
      }
      iframe.removeEventListener('load', onLoad);
    };
    iframe.addEventListener('load', onLoad);
    win.location.reload();
  }, [previewNonce]);

  const update = useCallback((key: string, value: string) => {
    dirtyRef.current = true;
    setSaveState('saving');
    setValues((prev) => ({ ...prev, [key]: value }));
  }, []);

  // Persist the current values immediately, bypassing the debounce. Called
  // before navigating away (notably to "Review & publish"): the debounced
  // autosave is cancelled on unmount, so without this an edit made within the
  // 800ms window would never reach the server and would be lost on publish.
  const flushSave = useCallback(async () => {
    if (!dirtyRef.current && !debounceControl.isPending()) return;
    // We save the full values map ourselves; drop any pending debounced run.
    debounceControl.cancel();
    setSaveState('saving');
    const res = isConfig
      ? await saveConfigTranslation(configType, configId, langcode, values, sourceHashRef.current)
      : await saveDraft(id, langcode, values, sourceHashRef.current);
    dirtyRef.current = false;
    setStatus(res.status);
    setSaveState('saved');
    if (res.sourceHash) sourceHashRef.current = res.sourceHash;
    if (res.sourceChanged) setSourceConflict(true);
  }, [values, id, langcode, isConfig, configType, configId, debounceControl]);

  const rows = useMemo(() => doc?.groups.flatMap((g) => g.rows) ?? [], [doc]);
  // Completeness counts only fields with source content; empty-source optional
  // fields can still be translated but don't drag the percentage down.
  const translatable = rows.filter((r) => r.source.trim() !== '');
  const filled = translatable.filter((r) => (values[r.key] ?? '').trim() !== '').length;
  const percent = translatable.length ? Math.round((filled / translatable.length) * 100) : 100;
  // Fields whose source drifted since the last translation (outdated docs only),
  // in document order — the worklist the guided review steps through.
  const reviewKeys = useMemo(() => rows.filter((r) => r.sourceWas !== undefined).map((r) => r.key), [rows]);
  const changed = reviewKeys.length;
  const reviewedCount = reviewKeys.filter((k) => reviewedKeys.has(k)).length;

  // Jump-to-next navigation: the ordered keys still needing the translator's
  // attention — empty target with source content, plus any field whose source
  // changed. Wraps around from a cursor tracking the last-visited key.
  const navRefs = useRef<Map<string, HTMLElement>>(new Map());
  const navCursor = useRef<string | null>(null);
  const reviewCursor = useRef<string | null>(null);
  const needsWork = useMemo(
    () =>
      rows
        .filter((r) => (r.source.trim() !== '' && (values[r.key] ?? '').trim() === '') || r.sourceWas !== undefined)
        .map((r) => r.key),
    [rows, values],
  );
  // Scrolls a field into view and focuses its editable target — the plain
  // textarea (skipping the read-only source one that precedes it), or, for
  // rich-text rows, CKEditor's contenteditable region. The source rich-text
  // preview is a plain div, so [contenteditable="true"] picks only the editor.
  const focusKey = useCallback((key: string) => {
    const el = navRefs.current.get(key);
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    const focusEl =
      el.querySelector<HTMLElement>('textarea:not([readonly])') ??
      el.querySelector<HTMLElement>('.ct-rte [contenteditable="true"]');
    focusEl?.focus();
  }, []);
  // Cycle a cursor through an ordered key list, wrapping around.
  const step = useCallback(
    (list: string[], cursor: React.MutableRefObject<string | null>, dir: 1 | -1) => {
      if (list.length === 0) return;
      const fromIdx = cursor.current ? list.indexOf(cursor.current) : (dir === 1 ? -1 : 0);
      const nextKey = list[(fromIdx + dir + list.length) % list.length];
      cursor.current = nextKey;
      focusKey(nextKey);
    },
    [focusKey],
  );
  // Step through every field needing work; in review mode, only changed fields.
  const goTo = useCallback((dir: 1 | -1) => step(needsWork, navCursor, dir), [step, needsWork]);
  const goToReview = useCallback((dir: 1 | -1) => step(reviewKeys, reviewCursor, dir), [step, reviewKeys]);

  // Alt+↓ / Alt+↑ hop between fields needing work (changed fields in review mode).
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!e.altKey || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
      e.preventDefault();
      const dir = e.key === 'ArrowDown' ? 1 : -1;
      if (reviewing) goToReview(dir);
      else goTo(dir);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [goTo, goToReview, reviewing]);

  // Enter guided review: step to the first changed field.
  const startReview = useCallback(() => {
    setReviewing(true);
    reviewCursor.current = null;
    if (reviewKeys.length > 0) {
      reviewCursor.current = reviewKeys[0];
      focusKey(reviewKeys[0]);
    }
  }, [reviewKeys, focusKey]);

  // Per-changed-field review actions (client-only worklist tracking).
  const onKeep = useCallback(
    (key: string) => {
      setReviewedKeys((prev) => new Set(prev).add(key));
      goToReview(1);
    },
    [goToReview],
  );
  const onUpdate = useCallback(
    (key: string) => {
      setReviewedKeys((prev) => new Set(prev).add(key));
      focusKey(key);
    },
    [focusKey],
  );

  // "Mark as reviewed": the existing translation is still valid for the changed
  // source. Clears the outdated flag server-side without rewriting any values.
  const onMarkReviewed = useCallback(async () => {
    setMarking(true);
    setError(null);
    try {
      if (isConfig) await markConfigReviewed(configType, configId, langcode);
      else await markReviewed(id, langcode);
      await load();
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    } finally {
      setMarking(false);
    }
  }, [isConfig, configType, configId, id, langcode, load]);

  const copyAllEmpty = useCallback(() => {
    dirtyRef.current = true;
    setSaveState('saving');
    setValues((prev) => {
      const next = { ...prev };
      rows.forEach((r) => {
        if (!r.readOnly && (next[r.key] ?? '').trim() === '') next[r.key] = r.source;
      });
      return next;
    });
  }, [rows]);

  // AI machine translation (optional canvas_translate_ai submodule). The
  // suggestions are merged into the draft exactly like manual edits, so the
  // existing autosave persists them and the editor reviews before publishing.
  const applyAi = useCallback(
    async (opId: string, toTranslate: FieldRowData[]) => {
      const items: AiTranslateItem[] = toTranslate
        .filter((r) => !r.readOnly && r.source.trim() !== '')
        .map((r) => ({ key: r.key, format: r.format, source: r.source }));
      if (items.length === 0) return;
      setAiBusy(opId);
      setError(null);
      try {
        const result = isConfig
          ? await aiTranslateConfig(configType, configId, langcode, items)
          : await aiTranslate(id, langcode, items);
        dirtyRef.current = true;
        setSaveState('saving');
        setValues((prev) => ({ ...prev, ...result }));
      } catch (err: unknown) {
        setError(err instanceof ApiError ? err.message : String(err));
      } finally {
        setAiBusy(null);
      }
    },
    [id, langcode, isConfig, configType, configId],
  );

  const aiTranslateEmpty = useCallback(
    () => applyAi('empty', rows.filter((r) => (values[r.key] ?? '').trim() === '')),
    [applyAi, rows, values],
  );
  const aiTranslateAll = useCallback(() => applyAi('all', rows), [applyAi, rows]);

  // Config items publish directly from the editor; content drafts are published
  // from the Review & publish workspace instead.
  const onPublishConfig = useCallback(async () => {
    if (!doc) return;
    setPublishing(true);
    setError(null);
    try {
      await publishConfigTranslation(configType, configId, langcode, values);
      await load();
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    } finally {
      setPublishing(false);
    }
  }, [doc, langcode, values, load, configType, configId]);

  const onDiscard = useCallback(async () => {
    setError(null);
    try {
      if (isConfig) {
        await discardConfigTranslation(configType, configId, langcode);
      } else {
        await discardDraft(id, langcode);
      }
      await load();
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    }
  }, [id, langcode, load, isConfig, configType, configId]);

  if (setupRequired && !doc) {
    return (
      <Centered>
        <SetupRequired onRetry={() => { setSetupRequired(false); load(); }} />
      </Centered>
    );
  }
  if (error && !doc) {
    return (
      <Centered>
        <Box style={{ maxWidth: 420 }}><Banner tone="error">{error}</Banner></Box>
      </Centered>
    );
  }
  if (!doc) {
    return <Centered><Flex align="center" gap="2"><Spinner /> <Text color="gray">Loading…</Text></Flex></Centered>;
  }

  const langName = (lc: string) => doc.languages.find((l) => l.id === lc)?.name ?? lc;
  const dir = (lc: string) => doc.languages.find((l) => l.id === lc)?.direction ?? 'ltr';
  const sourceDir = dir(doc.sourceLangcode);
  const targetDir = dir(doc.targetLangcode);

  return (
    <Flex direction="column" height="100%" overflow="hidden">
      <Box px="4" py="3" style={{ borderBottom: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
        {/* Identity row — what you're translating, and how far along. */}
        <Flex align="center" gap="3" wrap="wrap">
          <Button
            variant="soft"
            color="gray"
            onClick={async () => {
              try {
                await flushSave();
              } catch (err: unknown) {
                setSaveState('error');
                setError(err instanceof ApiError ? err.message : String(err));
              }
              navigate('/');
            }}
            title="Back to all items"
          >
            <ArrowLeftIcon />
            All pages
          </Button>
          <Box style={{ minWidth: 0 }}>
            <Heading size="3" weight="bold" truncate style={{ maxWidth: 320 }}>{doc.label}</Heading>
            <Flex align="center" gap="1" mt="1">
              <Text size="1" color="gray">{langName(doc.sourceLangcode)}</Text>
              <ArrowRightIcon width="12" height="12" color="var(--gray-9)" />
              <Text size="1" weight="medium">{langName(doc.targetLangcode)}</Text>
            </Flex>
          </Box>
          <StatusBadge status={status} />
          <Flex align="center" gap="4" ml="auto">
            <SaveIndicator state={saveState} />
            {reviewing ? (
              <>
                <Text size="1" color="amber" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>
                  Reviewing · {reviewedCount}/{changed}
                </Text>
                <Button size="1" variant="ghost" color="gray" onClick={() => goToReview(1)} title="Next change (Alt+↓)">
                  Next change
                  <ArrowDownIcon />
                </Button>
                <Button size="1" variant="ghost" color="gray" onClick={() => setReviewing(false)}>
                  Exit review
                </Button>
              </>
            ) : (
              <>
                {status === 'outdated' && changed > 0 && (
                  <Text size="1" color="amber" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>
                    {changed} changed
                  </Text>
                )}
                {needsWork.length > 0 && (
                  <Button
                    size="1"
                    variant="ghost"
                    color="gray"
                    onClick={() => goTo(1)}
                    title="Next field needing work (Alt+↓)"
                  >
                    Next field
                    <ArrowDownIcon />
                  </Button>
                )}
              </>
            )}
            <Text size="1" color="gray" style={{ fontVariantNumeric: 'tabular-nums' }}>
              {filled}/{translatable.length} fields
            </Text>
            <Box style={{ minWidth: 130 }}>
              <PercentBar percent={percent} color={statusMeta[status].color} />
            </Box>
          </Flex>
        </Flex>

        {/* Actions row — one primary (publish), one highlighted aid (AI), the
            rest demoted to ghost or tucked into the overflow menu so the
            hierarchy reads at a glance. */}
        <Flex align="center" gap="3" mt="3" wrap="wrap">
          {/* Secondary: the headline translation aid. */}
          {doc.aiAvailable && (
            <Button variant="soft" onClick={aiTranslateEmpty} disabled={aiBusy !== null} loading={aiBusy === 'empty'}>
              <MagicWandIcon />
              AI translate empty
            </Button>
          )}
          <Flex align="center" gap="3" ml="auto">
            {/* Tertiary: a view toggle, not an edit — ghost so it recedes. */}
            {!isConfig && (
              <Button variant="ghost" color="gray" onClick={() => setShowPreview((v) => !v)}>
                <EyeOpenIcon />
                {showPreview ? 'Hide preview' : 'Show preview'}
              </Button>
            )}

            {/* Overflow: bulk and destructive actions, kept out of the main row. */}
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <IconButton variant="ghost" color="gray" aria-label="More actions">
                  <DotsHorizontalIcon />
                </IconButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content>
                <DropdownMenu.Item onSelect={copyAllEmpty}>
                  <PinBottomIcon />
                  Copy source to empty
                </DropdownMenu.Item>
                {doc.aiAvailable && (
                  <DropdownMenu.Item onSelect={aiTranslateAll} disabled={aiBusy !== null}>
                    <ReloadIcon />
                    Re-translate all
                  </DropdownMenu.Item>
                )}
                {((!isConfig && status === 'draft') ||
                  (isConfig && (status === 'draft' || status === 'up_to_date' || status === 'outdated'))) && (
                  <>
                    <DropdownMenu.Separator />
                    <DropdownMenu.Item color="red" onSelect={onDiscard}>
                      <TrashIcon />
                      {status === 'draft' ? 'Discard draft' : 'Remove translation'}
                    </DropdownMenu.Item>
                  </>
                )}
              </DropdownMenu.Content>
            </DropdownMenu.Root>

            {/* Outdated, no draft: confirm the existing translation still holds
                without re-translating. (status === 'outdated' implies no draft —
                a draft would report 'draft'.) */}
            {status === 'outdated' && (
              <Button variant="soft" color="amber" onClick={onMarkReviewed} disabled={marking} loading={marking}>
                <CheckIcon />
                Mark as reviewed
              </Button>
            )}

            {/* Primary: the goal of the page. */}
            {isConfig ? (
              <Button onClick={onPublishConfig} disabled={publishing} loading={publishing}>Publish</Button>
            ) : (
              // Content drafts publish from the Review & publish workspace.
              // Flush any unsaved edit first, else publishing reads a stale draft.
              <Button
                onClick={async () => {
                  try {
                    await flushSave();
                    navigate('/review');
                  } catch (err: unknown) {
                    setSaveState('error');
                    setError(err instanceof ApiError ? err.message : String(err));
                  }
                }}
              >
                Review &amp; publish
                <ArrowRightIcon />
              </Button>
            )}
          </Flex>
        </Flex>
        {error && <Box mt="3"><Banner tone="error">{error}</Banner></Box>}
      </Box>

      <Flex flexGrow="1" overflow="hidden" ref={splitRef}>
        <Box flexGrow="1" overflow="auto" px="4" pb="6" style={{ background: 'var(--color-background)', minWidth: 0 }}>
          {/* The source changed in Canvas while this draft was open. The draft is
              safe; reload to fold in the new changes and review them. */}
          {sourceConflict && (
            <ReviewBanner
              icon={<ExclamationTriangleIcon style={{ color: 'var(--amber-11)' }} />}
              actions={
                <Button size="2" color="amber" onClick={() => { setSourceConflict(false); load(); }}>
                  Review changes
                  <ArrowDownIcon />
                </Button>
              }
            >
              The source changed in Canvas while you were translating. Your work is saved.
            </ReviewBanner>
          )}

          {/* Outdated on open: acknowledge the drift and offer a guided review. */}
          {!sourceConflict && status === 'outdated' && changed > 0 && !reviewing && (
            <ReviewBanner
              icon={<ExclamationTriangleIcon style={{ color: 'var(--amber-11)' }} />}
              actions={
                <>
                  <Button size="2" color="amber" onClick={startReview}>
                    Review changes
                    <ArrowDownIcon />
                  </Button>
                  <Button size="2" variant="soft" color="amber" onClick={onMarkReviewed} disabled={marking} loading={marking}>
                    <CheckIcon />
                    Mark as reviewed
                  </Button>
                </>
              }
            >
              <Text weight="medium">{changed} {changed === 1 ? 'string' : 'strings'}</Text> changed in the source since you last published.
            </ReviewBanner>
          )}

          {doc.groups.map((group) => {
            const groupRows = group.rows;
            const groupFilled = groupRows.filter((r) => (values[r.key] ?? '').trim() !== '').length;
            const groupDone = groupFilled === groupRows.length;
            return (
              <Box key={group.component?.uuid ?? group.label}>
                <Flex align="center" gap="2" pt="4" pb="1" position="sticky" top="0" style={{ background: 'var(--color-background)', zIndex: 1 }}>
                  <Heading size="2" weight="bold">{group.label}</Heading>
                  <Flex
                    align="center"
                    gap="1"
                    style={{ color: groupDone ? 'var(--green-11)' : 'var(--gray-9)', fontVariantNumeric: 'tabular-nums' }}
                  >
                    {groupDone && <CheckCircledIcon width="13" height="13" />}
                    <Text size="1" weight="medium">{groupFilled}/{groupRows.length}</Text>
                  </Flex>
                  <Separator size="4" style={{ flex: 1 }} />
                </Flex>
                {groupRows.map((row) => (
                  <FieldRow
                    key={row.key}
                    row={row}
                    value={values[row.key] ?? ''}
                    onChange={(v) => update(row.key, v)}
                    registerNav={(el) => {
                      if (el) navRefs.current.set(row.key, el);
                      else navRefs.current.delete(row.key);
                    }}
                    sourceDir={sourceDir}
                    targetDir={targetDir}
                    onAi={doc.aiAvailable ? () => applyAi(row.key, [row]) : undefined}
                    aiBusy={aiBusy === row.key}
                    aiDisabled={aiBusy !== null}
                    reviewMode={reviewing}
                    reviewed={reviewedKeys.has(row.key)}
                    onKeep={() => onKeep(row.key)}
                    onUpdate={() => onUpdate(row.key)}
                  />
                ))}
              </Box>
            );
          })}
        </Box>

        {showPreview && !isConfig && (
          <>
            <div
              className="ct-resize"
              role="separator"
              aria-orientation="vertical"
              aria-label="Resize preview"
              onPointerDown={onResizeDown}
              onPointerMove={onResizeMove}
              onPointerUp={onResizeUp}
            >
              <span className="ct-resize__grip" />
            </div>
            <Flex direction="column" style={{ width: previewWidth, flexShrink: 0, background: 'var(--color-panel-solid)' }}>
              <iframe
                ref={previewRef}
                title={`${langName(doc.targetLangcode)} preview`}
                src={`${basePath()}canvas-translate/preview/canvas_page/${id}/${langcode}`}
                style={{ flex: 1, width: '100%', border: 'none' }}
              />
            </Flex>
          </>
        )}
      </Flex>
    </Flex>
  );
}

/**
 * An amber, in-content prompt for source-drift actions (review / mark reviewed /
 * reload after a concurrent edit). Matches the per-field "Source changed" box so
 * the whole staleness story reads as one treatment.
 */
function ReviewBanner({
  icon,
  actions,
  children,
}: {
  icon: React.ReactNode;
  actions: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <Flex
      align="center"
      gap="3"
      wrap="wrap"
      mt="4"
      p="3"
      style={{ background: 'var(--amber-2)', border: '1px solid var(--amber-6)', borderRadius: 'var(--radius-3)' }}
    >
      <Flex align="center" gap="2" style={{ minWidth: 0 }}>
        {icon}
        <Text size="2" style={{ color: 'var(--amber-12)' }}>{children}</Text>
      </Flex>
      <Flex gap="2" ml="auto">{actions}</Flex>
    </Flex>
  );
}

function SaveIndicator({ state }: { state: SaveState }) {
  if (state === 'idle') return null;
  const color = state === 'error' ? 'red' : state === 'saved' ? 'green' : 'gray';
  const text = state === 'saving' ? 'Saving…' : state === 'saved' ? 'Saved' : 'Save failed';
  return (
    <Flex align="center" gap="2">
      {state === 'saving' ? (
        <Spinner size="1" />
      ) : (
        <Box style={{ width: 6, height: 6, borderRadius: '50%', background: `var(--${color}-9)` }} />
      )}
      <Text size="1" color={color === 'green' ? 'gray' : color}>{text}</Text>
    </Flex>
  );
}

function Centered({ children }: { children: React.ReactNode }) {
  return (
    <Flex align="center" justify="center" height="100%">{children}</Flex>
  );
}
