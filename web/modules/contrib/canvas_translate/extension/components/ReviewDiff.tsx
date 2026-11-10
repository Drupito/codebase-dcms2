import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge, Box, Button, Flex, Heading, Separator, Spinner, Switch, Text } from '@radix-ui/themes';
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  Cross2Icon,
  Pencil1Icon,
  TrashIcon,
} from '@radix-ui/react-icons';
import {
  ApiError,
  approve,
  approveConfig,
  discardConfigTranslation,
  discardDraft,
  fetchConfigTranslation,
  fetchItems,
  fetchTranslation,
  unapprove,
  unapproveConfig,
} from '../api.ts';
import type { ConfigType, FieldGroup, FieldRowData, TranslationDoc } from '../types.ts';
import { statusMeta } from '../theme.ts';
import { PercentBar, StatusBadge } from './StatusBadge.tsx';
import { SourceDiff } from './FieldRow.tsx';
import { Banner } from './Banner.tsx';
import { type DraftRow, draftRowKey, editorPath, flattenDrafts, reviewPath } from '../drafts.ts';

/** Per-field review state, derived from the row's source/target/diff data. */
type FieldState = 'untranslated' | 'changed' | 'new' | 'translated';

function fieldState(row: FieldRowData): FieldState {
  const empty = row.target.trim() === '';
  if (empty && row.source.trim() !== '') return 'untranslated';
  // targetWas is present only when the draft changes this field; an empty
  // baseline means the string is brand new (all-added in the diff).
  if (row.targetWas !== undefined) return row.targetWas.trim() === '' ? 'new' : 'changed';
  return 'translated';
}

const STATE_META: Record<FieldState, { label: string; color: 'gray' | 'blue' | 'green' }> = {
  untranslated: { label: 'Untranslated', color: 'gray' },
  changed: { label: 'Changed', color: 'blue' },
  new: { label: 'New', color: 'blue' },
  translated: { label: 'Translated', color: 'green' },
};

/** Read-only rendering of a field value: rich text as HTML, plain text as-is. */
function ReadOnlyValue({ html, text, dir, muted }: { html: boolean; text: string; dir: string; muted?: boolean }) {
  const empty = text.trim() === '';
  const style: React.CSSProperties = {
    minHeight: 38,
    padding: 'var(--space-2)',
    color: muted ? 'var(--gray-11)' : 'var(--gray-12)',
    background: 'var(--color-surface)',
    border: '1px solid var(--gray-6)',
    borderRadius: 'var(--radius-3)',
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
  };
  if (empty) {
    return <Box dir={dir} style={style}><Text size="2" color="gray" style={{ fontStyle: 'italic' }}>Empty</Text></Box>;
  }
  if (html) {
    return <Box dir={dir} style={style} dangerouslySetInnerHTML={{ __html: text }} />;
  }
  return <Box dir={dir} style={style}><Text size="2">{text}</Text></Box>;
}

/** One field, shown read-only: source on the left, the change on the right. */
function ReviewField({ row, sourceDir, targetDir }: { row: FieldRowData; sourceDir: string; targetDir: string }) {
  const isRich = row.format !== null;
  const state = fieldState(row);
  const meta = STATE_META[state];
  const dot = meta.color === 'green' ? 'var(--green-9)' : meta.color === 'blue' ? 'var(--accent-9)' : 'var(--gray-8)';
  // Show the colorized change diff only for plain text — word-diffing rich-text
  // HTML would corrupt tags (same trade-off as the source-drift box). Rich
  // changed fields show the new value with the "Changed"/"New" badge instead.
  const showDiff = row.targetWas !== undefined && !isRich;

  return (
    <Box py="3" className="ct-field" style={{ borderTop: '1px solid var(--gray-4)' }}>
      {/* Source — the original, for reference. */}
      <Box>
        <Text size="1" weight="medium" color="gray" mb="1" style={{ display: 'block' }}>{row.label}</Text>
        {/* Source drifted since the last publish (outdated docs): show what
            changed in the original so the reviewer knows what to check. */}
        {row.sourceWas !== undefined && (
          <Box mb="1" p="2" style={{ background: 'var(--amber-2)', border: '1px solid var(--amber-6)', borderRadius: 'var(--radius-3)' }}>
            <Text size="1" weight="medium" mb="1" style={{ display: 'block', color: 'var(--amber-12)' }}>
              Source changed since last translation
            </Text>
            {isRich ? (
              <Box dir={sourceDir} style={{ color: 'var(--gray-12)', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }} dangerouslySetInnerHTML={{ __html: row.sourceWas || '<em>Empty</em>' }} />
            ) : (
              <SourceDiff oldText={row.sourceWas} newText={row.source} dir={sourceDir} />
            )}
          </Box>
        )}
        <ReadOnlyValue html={isRich} text={row.source} dir={sourceDir} muted />
      </Box>

      {/* Target — the change being shipped. */}
      <Box>
        <Flex justify="between" align="center" mb="1" style={{ minHeight: 20 }}>
          <Flex align="center" gap="2">
            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: dot }} />
            <Text size="1" weight="medium" color="gray">Translation</Text>
          </Flex>
          <Badge color={meta.color} variant="soft" radius="full" size="1">{meta.label}</Badge>
        </Flex>
        {showDiff ? (
          <Box p="2" style={{ background: 'var(--color-surface)', border: '1px solid var(--gray-6)', borderRadius: 'var(--radius-3)', minHeight: 38 }}>
            <SourceDiff oldText={row.targetWas ?? ''} newText={row.target} dir={targetDir} />
          </Box>
        ) : (
          <ReadOnlyValue html={isRich} text={row.target} dir={targetDir} />
        )}
      </Box>
    </Box>
  );
}

/**
 * Read-only review surface for a single pending translation draft: source vs the
 * change being shipped, field by field, with prev/next through the review queue
 * and approve / open-to-edit / discard. Reachable from "Review & publish".
 */
export default function ReviewDiff() {
  const params = useParams<{ id: string; langcode: string; type?: string }>();
  const isConfig = params.type !== undefined;
  const configType = (params.type ?? '') as ConfigType;
  const configId = params.id ?? '';
  const id = Number(params.id);
  const langcode = params.langcode ?? '';
  const navigate = useNavigate();

  const [doc, setDoc] = useState<TranslationDoc | null>(null);
  const [queue, setQueue] = useState<DraftRow[]>([]);
  const [approved, setApproved] = useState(false);
  // Default to the change view: reviewers care first about what publishing
  // changes. Toggle off to see every field (including untranslated ones).
  const [changesOnly, setChangesOnly] = useState(true);
  const [busy, setBusy] = useState<'approve' | 'unapprove' | 'discard' | null>(null);
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selfKey = draftRowKey({ kind: isConfig ? 'config' : 'content', id: isConfig ? configId : id, langcode });

  // The doc to review. fetchItems backs prev/next; a failure there only disables
  // navigation, so don't surface it as a blocking error.
  useEffect(() => {
    let cancelled = false;
    const request = isConfig ? fetchConfigTranslation(configType, configId, langcode) : fetchTranslation(id, langcode);
    request
      .then((res) => { if (!cancelled) { setDoc(res); setApproved(res.approved ?? false); setError(null); } })
      .catch((err: unknown) => { if (!cancelled) setError(err instanceof ApiError ? err.message : String(err)); });
    fetchItems().then((res) => { if (!cancelled) setQueue(flattenDrafts(res)); }).catch(() => {});
    return () => { cancelled = true; };
  }, [isConfig, configType, configId, id, langcode]);

  const idx = useMemo(() => queue.findIndex((r) => draftRowKey(r) === selfKey), [queue, selfKey]);

  const go = useCallback((delta: number) => {
    if (idx < 0) return;
    const next = queue[idx + delta];
    if (next) navigate(reviewPath(next));
  }, [idx, queue, navigate]);

  // Auto-advance to the next item in the queue after approving (the item stays
  // in the queue, now approved). Falls back to the queue when this was the last.
  const advanceNext = useCallback(() => {
    const next = idx >= 0 ? queue[idx + 1] : undefined;
    navigate(next ? reviewPath(next) : '/review');
  }, [idx, queue, navigate]);

  // After discard the item leaves the queue, so re-fetch and step to whatever
  // now sits at this position (or the queue when none remain).
  const advanceAfterRemoval = useCallback(async () => {
    const fresh = flattenDrafts(await fetchItems().catch(() => null));
    const next = fresh[Math.min(Math.max(idx, 0), fresh.length - 1)];
    navigate(next && fresh.length > 0 ? reviewPath(next) : '/review');
  }, [idx, navigate]);

  const onApprove = useCallback(async () => {
    setBusy('approve');
    setError(null);
    try {
      if (isConfig) await approveConfig(configType, configId, langcode);
      else await approve(id, langcode);
      advanceNext();
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }, [isConfig, configType, configId, id, langcode, advanceNext]);

  const onUnapprove = useCallback(async () => {
    setBusy('unapprove');
    setError(null);
    try {
      if (isConfig) await unapproveConfig(configType, configId, langcode);
      else await unapprove(id, langcode);
      setApproved(false);
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }, [isConfig, configType, configId, id, langcode]);

  const onDiscard = useCallback(async () => {
    setBusy('discard');
    setError(null);
    try {
      if (isConfig) await discardConfigTranslation(configType, configId, langcode);
      else await discardDraft(id, langcode);
      await advanceAfterRemoval();
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }, [isConfig, configType, configId, id, langcode, advanceAfterRemoval]);

  if (error && !doc) {
    return <Centered><Box style={{ maxWidth: 420 }}><Banner tone="error">{error}</Banner></Box></Centered>;
  }
  if (!doc) {
    return <Centered><Flex align="center" gap="2"><Spinner /> <Text color="gray">Loading…</Text></Flex></Centered>;
  }

  const langName = (lc: string) => doc.languages.find((l) => l.id === lc)?.name ?? lc;
  const dir = (lc: string) => doc.languages.find((l) => l.id === lc)?.direction ?? 'ltr';
  const sourceDir = dir(doc.sourceLangcode);
  const targetDir = dir(doc.targetLangcode);

  const allRows = doc.groups.flatMap((g) => g.rows);
  const translatable = allRows.filter((r) => r.source.trim() !== '');
  const filled = translatable.filter((r) => r.target.trim() !== '').length;
  const percent = translatable.length ? Math.round((filled / translatable.length) * 100) : 100;
  // A field is a "change" only when the draft actually changes what's published
  // (targetWas) or the source drifted (sourceWas). An untranslated/empty field
  // ships no change — publishing leaves it falling back to source — so it is not
  // part of the change view.
  const isInteresting = (r: FieldRowData) => r.targetWas !== undefined || r.sourceWas !== undefined;
  const changeCount = allRows.filter(isInteresting).length;

  // Apply the "changes only" filter, dropping groups left empty.
  const groups: FieldGroup[] = changesOnly
    ? doc.groups.map((g) => ({ ...g, rows: g.rows.filter(isInteresting) })).filter((g) => g.rows.length > 0)
    : doc.groups;

  return (
    <Flex direction="column" height="100%" overflow="hidden">
      <Box px="4" py="3" style={{ borderBottom: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
        <Flex align="center" gap="3" wrap="wrap">
          <Button variant="soft" color="gray" onClick={() => navigate('/review')} title="Back to the review queue">
            <ArrowLeftIcon />
            Review queue
          </Button>
          <Box style={{ minWidth: 0 }}>
            <Heading size="3" weight="bold" truncate style={{ maxWidth: 320 }}>{doc.label}</Heading>
            <Flex align="center" gap="1" mt="1">
              <Text size="1" color="gray">{langName(doc.sourceLangcode)}</Text>
              <ArrowRightIcon width="12" height="12" color="var(--gray-9)" />
              <Text size="1" weight="medium">{langName(doc.targetLangcode)}</Text>
            </Flex>
          </Box>
          <StatusBadge status={doc.status} />
          <Flex align="center" gap="4" ml="auto">
            {idx >= 0 && queue.length > 1 && (
              <Flex align="center" gap="1">
                <Button size="1" variant="ghost" color="gray" disabled={idx <= 0} onClick={() => go(-1)} title="Previous draft">
                  <ChevronLeftIcon />
                </Button>
                <Text size="1" color="gray" style={{ fontVariantNumeric: 'tabular-nums' }}>{idx + 1}/{queue.length}</Text>
                <Button size="1" variant="ghost" color="gray" disabled={idx >= queue.length - 1} onClick={() => go(1)} title="Next draft">
                  <ChevronRightIcon />
                </Button>
              </Flex>
            )}
            <Text size="1" color="gray" style={{ fontVariantNumeric: 'tabular-nums' }}>{filled}/{translatable.length} fields</Text>
            <Box style={{ minWidth: 130 }}>
              <PercentBar percent={percent} color={statusMeta[doc.status].color} />
            </Box>
          </Flex>
        </Flex>

        <Flex align="center" gap="3" mt="3" wrap="wrap">
          {allRows.length > 0 && (
            <Text as="label" size="1" color="gray">
              <Flex align="center" gap="2">
                <Switch size="1" checked={changesOnly} onCheckedChange={setChangesOnly} />
                Changes only ({changeCount})
              </Flex>
            </Text>
          )}
          <Flex align="center" gap="3" ml="auto">
            {confirmingDiscard ? (
              <Flex align="center" gap="2">
                <Text size="2" color="gray">Discard this draft?</Text>
                <Button color="red" onClick={() => { setConfirmingDiscard(false); onDiscard(); }} disabled={busy !== null} loading={busy === 'discard'}>
                  <TrashIcon />
                  Discard
                </Button>
                <Button variant="soft" color="gray" onClick={() => setConfirmingDiscard(false)} disabled={busy !== null}>
                  Cancel
                </Button>
              </Flex>
            ) : (
              <Button variant="ghost" color="red" onClick={() => setConfirmingDiscard(true)} disabled={busy !== null}>
                <TrashIcon />
                Discard
              </Button>
            )}
            <Button variant="soft" color="gray" onClick={() => navigate(editorPath({ kind: isConfig ? 'config' : 'content', type: configType, id: isConfig ? configId : id, langcode }))} disabled={busy !== null}>
              <Pencil1Icon />
              Edit
            </Button>
            {approved ? (
              <Flex align="center" gap="2">
                <Flex align="center" gap="1" style={{ color: 'var(--green-11)' }}>
                  <CheckIcon />
                  <Text size="2" weight="medium">Approved</Text>
                </Flex>
                <Button variant="soft" color="gray" onClick={onUnapprove} disabled={busy !== null} loading={busy === 'unapprove'}>
                  <Cross2Icon />
                  Undo
                </Button>
              </Flex>
            ) : (
              <Button color="green" onClick={onApprove} disabled={busy !== null} loading={busy === 'approve'}>
                <CheckIcon />
                Approve
              </Button>
            )}
          </Flex>
        </Flex>
        {error && <Box mt="3"><Banner tone="error">{error}</Banner></Box>}
      </Box>

      <Box flexGrow="1" overflow="auto" px="4" pb="6" style={{ background: 'var(--color-background)' }}>
        {groups.length === 0 ? (
          <Flex direction="column" align="center" justify="center" gap="2" py="8" style={{ textAlign: 'center' }}>
            <Heading size="3">No changes to review</Heading>
            <Text size="2" color="gray">
              This draft doesn’t change any published strings. Turn off “Changes only” to see all fields.
            </Text>
          </Flex>
        ) : (
          groups.map((group) => (
            <Box key={group.component?.uuid ?? group.label}>
              <Flex align="center" gap="2" pt="4" pb="1" position="sticky" top="0" style={{ background: 'var(--color-background)', zIndex: 1 }}>
                <Heading size="2" weight="bold">{group.label}</Heading>
                <Separator size="4" style={{ flex: 1 }} />
              </Flex>
              {group.rows.map((row) => (
                <ReviewField key={row.key} row={row} sourceDir={sourceDir} targetDir={targetDir} />
              ))}
            </Box>
          ))
        )}
      </Box>
    </Flex>
  );
}

function Centered({ children }: { children: React.ReactNode }) {
  return <Flex align="center" justify="center" height="100%">{children}</Flex>;
}
