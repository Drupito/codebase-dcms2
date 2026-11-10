import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useDebounce } from 'use-debounce';
import {
  Badge,
  Box,
  Button,
  Flex,
  Heading,
  Select,
  Table,
  Text,
  TextField,
} from '@radix-ui/themes';
import { ArrowRightIcon, ChevronRightIcon, GlobeIcon, MagnifyingGlassIcon, Pencil1Icon } from '@radix-ui/react-icons';
import { ApiError, fetchItems } from '../api.ts';
import type { DashboardResponse, Status, TranslationState } from '../types.ts';
import type { DashboardItem } from '../types.ts';
import { statusMeta } from '../theme.ts';
import { targetLanguages as computeTargetLanguages } from '../columns.ts';
import { PercentBar, StatusBadge } from './StatusBadge.tsx';
import { Banner } from './Banner.tsx';

const STATUS_FILTERS: Array<{ value: 'all' | Status; label: string }> = [
  { value: 'all', label: 'All statuses' },
  { value: 'not_translated', label: 'Not translated' },
  { value: 'draft', label: 'Draft' },
  { value: 'outdated', label: 'Outdated' },
  { value: 'up_to_date', label: 'Up to date' },
];

/** Human-readable label for an item's type, shown in the Type column. */
function typeLabel(item: DashboardItem): string {
  if (item.kind === 'config') {
    return { content_template: 'Template', page_variant: 'Page template' }[item.type] ?? 'Region';
  }
  return 'Page';
}

/** The editor route for an item, branching on content vs config. */
function editorPath(item: DashboardItem, langcode: string): string {
  return item.kind === 'config'
    ? `/config/${item.type}/${encodeURIComponent(String(item.id))}/${langcode}`
    : `/page/${item.id}/${langcode}`;
}

export default function Dashboard() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [debouncedSearch] = useDebounce(search, 250);
  // Radix Select forbids an empty-string item value, so the "all" sentinel
  // stands in for "no filter" and maps back to undefined on the request.
  const [langcode, setLangcode] = useState('all');
  const [status, setStatus] = useState<'all' | Status>('all');
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  // langcode only narrows the server result set when a status filter is active
  // (see ApiTranslateController::matchesStatusFilter). With no status filter the
  // same items come back for every language — each item already carries all
  // languages — so we keep langcode out of the request and switch the rail's
  // view client-side: instant, no refetch, no loading flicker.
  const serverLangcode = status === 'all' || langcode === 'all' ? undefined : langcode;

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    fetchItems({
      search: debouncedSearch,
      langcode: serverLangcode,
      status: status === 'all' ? undefined : status,
    })
      .then((res) => {
        if (!cancelled) {
          setData(res);
          setError(null);
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : String(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [debouncedSearch, serverLangcode, status]);

  const targetLanguages = useMemo(
    () => computeTargetLanguages(data?.languages, data?.items),
    [data?.languages, data?.items],
  );

  // Per-language completion for the rail. Averages each item's percent; reflects
  // the current search/status filter, which is what the visible list shows.
  // ponytail: rough aggregate, fine for an at-a-glance rail.
  const langStats = useMemo(() => {
    const items = data?.items ?? [];
    const out: Record<string, { percent: number; total: number }> = {};
    for (const l of targetLanguages) {
      const sum = items.reduce((n, it) => n + (it.translations[l.id]?.percent ?? 0), 0);
      out[l.id] = { percent: items.length ? Math.round(sum / items.length) : 0, total: items.length };
    }
    return out;
  }, [data?.items, targetLanguages]);

  // Pending drafts across all pages/languages — the Review & publish queue.
  // Both content and config items can have draft cells.
  const draftCount = useMemo(
    () =>
      (data?.items ?? []).reduce(
        (n, item) => n + Object.values(item.translations).filter((s) => s.status === 'draft').length,
        0,
      ),
    [data?.items],
  );

  // Outdated cells across the visible set — the "needs review" triage count.
  const outdatedCount = useMemo(
    () =>
      (data?.items ?? []).reduce(
        (n, item) => n + Object.values(item.translations).filter((s) => s.status === 'outdated').length,
        0,
      ),
    [data?.items],
  );

  const focused = langcode !== 'all';
  const focusedLang = targetLanguages.find((l) => l.id === langcode);

  return (
    <Flex direction="column" height="100%" overflow="hidden">
      {/* Scoped hover affordances — everything else is Radix Themes tokens. */}
      <style>{`
        .ct-row { cursor: pointer; transition: background 0.12s ease; }
        .ct-row:hover { background: var(--gray-3); }
        .ct-cell { position: relative; cursor: pointer; transition: background 0.12s ease; border-radius: var(--radius-2); }
        .ct-cell:hover { background: var(--gray-3); }
        .ct-cell-edit { opacity: 0; pointer-events: none; transition: opacity 0.12s ease; }
        .ct-cell:hover .ct-cell-edit { opacity: 1; }
        .ct-rail-item { cursor: pointer; transition: background 0.12s ease; }
        .ct-rail-item:hover { background: var(--gray-3); }
        .ct-rail-item[data-active="true"] { background: var(--accent-3); }
        .ct-rail-item[data-active="true"]:hover { background: var(--accent-4); }
        .ct-go-icon { color: var(--gray-8); transition: color 0.12s ease, transform 0.12s ease; }
        .ct-go-label { opacity: 0; transition: opacity 0.12s ease; }
        .ct-row:hover .ct-go-icon { color: var(--accent-11); transform: translateX(2px); }
        .ct-row:hover .ct-go-label { opacity: 1; }
      `}</style>

      <Box p="4" style={{ borderBottom: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
        <Flex align="start" gap="3">
          <Box flexGrow="1">
            <Heading size="5" weight="bold">Translate</Heading>
            <Text as="p" size="2" color="gray" mt="1">
              Translate your pages, templates and regions into every language.
            </Text>
          </Box>
          {outdatedCount > 0 && status !== 'outdated' && (
            <Button variant="soft" color="amber" onClick={() => setStatus('outdated')} title="Show translations whose source changed">
              Needs review
              <Badge color="amber" variant="solid" radius="full" highContrast>{outdatedCount}</Badge>
            </Button>
          )}
          {/* Always present so the publish path is discoverable; disabled when
              there's nothing pending, which itself signals "nothing to publish". */}
          <Button
            onClick={() => navigate('/review')}
            disabled={draftCount === 0}
            title={draftCount > 0 ? 'Review and publish pending translations' : 'No translations to publish'}
          >
            Review translations{draftCount > 0 ? ` (${draftCount})` : ''}
            <ArrowRightIcon />
          </Button>
        </Flex>

        <Flex gap="2" mt="3" wrap="wrap">
          <Box flexGrow="1" style={{ minWidth: 200 }}>
            <TextField.Root
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search pages, templates, regions…"
            >
              <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
            </TextField.Root>
          </Box>
          <Select.Root value={status} onValueChange={(v) => setStatus(v as 'all' | Status)}>
            <Select.Trigger style={{ minWidth: 150 }} />
            <Select.Content>
              {STATUS_FILTERS.map((s) => (
                <Select.Item key={s.value} value={s.value}>{s.label}</Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </Flex>
      </Box>

      {/* Language rail (navigation) + the list it scopes. */}
      <Flex flexGrow="1" overflow="hidden">
        <LanguageRail
          languages={targetLanguages}
          stats={langStats}
          value={langcode}
          onChange={setLangcode}
        />

        <Box flexGrow="1" overflow="auto">
          {error && <Box p="4"><Banner tone="error">{error}</Banner></Box>}
          {loading && !data && <SkeletonTable />}

          {data && data.items.length === 0 && !loading && (
            <EmptyState
              icon={<GlobeIcon width="22" height="22" />}
              title="Nothing to show here"
              body="No items match these filters. Try clearing the search or switching language."
            />
          )}

          {data && data.items.length > 0 && !focused && (
            <OverviewGrid
              items={data.items}
              languages={targetLanguages}
              onOpen={(item, lc) => navigate(editorPath(item, lc))}
            />
          )}

          {data && data.items.length > 0 && focused && focusedLang && (
            <FocusedList
              items={data.items}
              langcode={langcode}
              langName={focusedLang.name}
              onOpen={(item) => navigate(editorPath(item, langcode))}
            />
          )}
        </Box>
      </Flex>

      <Legend />
    </Flex>
  );
}

/** Left navigation: "All languages" overview plus one entry per language,
 * each showing how far along that language is. The clear affordance for the
 * all ⇄ single-language switch — always visible, one click. */
function LanguageRail({
  languages,
  stats,
  value,
  onChange,
}: {
  languages: { id: string; name: string }[];
  stats: Record<string, { percent: number; total: number }>;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <Box
      p="2"
      style={{ width: 224, flexShrink: 0, borderRight: '1px solid var(--gray-5)', overflowY: 'auto' }}
    >
      <RailItem active={value === 'all'} onClick={() => onChange('all')}>
        <Flex align="center" gap="2">
          <GlobeIcon style={{ color: 'var(--gray-10)' }} />
          <Text size="2" weight="medium">All languages</Text>
        </Flex>
      </RailItem>

      <Text size="1" color="gray" weight="medium" ml="2" mt="3" mb="1" as="div"
        style={{ textTransform: 'uppercase', letterSpacing: 0.4 }}>
        Languages
      </Text>
      {languages.map((l) => {
        const s = stats[l.id];
        const pct = s?.percent ?? 0;
        const color = pct >= 100 ? 'green' : pct === 0 ? 'gray' : 'blue';
        return (
          <RailItem key={l.id} active={value === l.id} onClick={() => onChange(l.id)}>
            <Text size="2" weight="medium" truncate as="div">{l.name}</Text>
            <Box mt="1"><PercentBar percent={pct} color={color} /></Box>
          </RailItem>
        );
      })}
    </Box>
  );
}

function RailItem({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <Box
      role="button"
      tabIndex={0}
      data-active={active}
      onClick={onClick}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onClick();
        }
      }}
      className="ct-rail-item"
      px="2"
      py="2"
      style={{ borderRadius: 'var(--radius-3)' }}
    >
      {children}
    </Box>
  );
}

/** All-languages overview: a matrix of item × language status cells. */
function OverviewGrid({
  items,
  languages,
  onOpen,
}: {
  items: DashboardItem[];
  languages: { id: string; name: string }[];
  onOpen: (item: DashboardItem, langcode: string) => void;
}) {
  return (
    <Table.Root variant="surface" size="1" m="4" className="ct-fade-in">
      <Table.Header>
        <Table.Row>
          <Table.ColumnHeaderCell>Item</Table.ColumnHeaderCell>
          <Table.ColumnHeaderCell width="96px">Type</Table.ColumnHeaderCell>
          {languages.map((l) => (
            <Table.ColumnHeaderCell key={l.id} width="160px">{l.name}</Table.ColumnHeaderCell>
          ))}
        </Table.Row>
      </Table.Header>
      <Table.Body>
        {items.map((item) => (
          <Table.Row key={`${item.kind}:${item.type}:${item.id}`} align="center">
            <Table.RowHeaderCell>
              <Text weight="medium">{item.label}</Text>
            </Table.RowHeaderCell>
            <Table.Cell>
              <Badge color="gray" variant="soft">{typeLabel(item)}</Badge>
            </Table.Cell>
            {languages.map((l) => {
              const state: TranslationState | undefined = item.translations[l.id];
              const st = state?.status ?? 'not_translated';
              // The source language is edited in Canvas, not here, so its cell is
              // a static label rather than a translation affordance.
              if (st === 'source') {
                return (
                  <Table.Cell key={l.id}>
                    <Flex align="center" gap="1" p="1" title={`“${item.label}” was authored in ${l.name}`}>
                      <Text size="1" color="gray">Source language</Text>
                    </Flex>
                  </Table.Cell>
                );
              }
              return (
                <Table.Cell key={l.id}>
                  <Flex
                    direction="column"
                    gap="1"
                    align="start"
                    p="1"
                    className="ct-cell"
                    role="button"
                    tabIndex={0}
                    onClick={() => onOpen(item, l.id)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        onOpen(item, l.id);
                      }
                    }}
                    title={`Translate “${item.label}” to ${l.name} — ${state?.percent ?? 0}% complete`}
                  >
                    <StatusBadge status={st} draft={state?.hasDraft} changedStrings={state?.changedStrings} />
                    <Box width="100%"><PercentBar percent={state?.percent ?? 0} color={statusMeta[st].color} /></Box>
                    {/* Floats over the cell on hover — the edit affordance. */}
                    <Flex
                      className="ct-cell-edit"
                      align="center"
                      gap="1"
                      style={{
                        position: 'absolute',
                        top: '50%',
                        left: '50%',
                        transform: 'translate(-50%, -50%)',
                        padding: '3px 8px',
                        borderRadius: 'var(--radius-4)',
                        background: 'var(--color-panel-solid)',
                        border: '1px solid var(--gray-5)',
                        boxShadow: 'var(--shadow-2)',
                        color: 'var(--accent-11)',
                      }}
                    >
                      <Pencil1Icon width="12" height="12" />
                      <Text size="1" weight="medium">{st === 'not_translated' ? 'Translate' : 'Edit'}</Text>
                    </Flex>
                  </Flex>
                </Table.Cell>
              );
            })}
          </Table.Row>
        ))}
      </Table.Body>
    </Table.Root>
  );
}

/** Single-language focused mode: one clear, actionable row per item. */
function FocusedList({
  items,
  langcode,
  langName,
  onOpen,
}: {
  items: DashboardItem[];
  langcode: string;
  langName: string;
  onOpen: (item: DashboardItem) => void;
}) {
  const done = items.filter((i) => i.translations[langcode]?.status === 'up_to_date').length;
  return (
    <Box className="ct-fade-in" m="4">
      <Flex align="baseline" gap="2" mb="2">
        <Text size="2" color="gray">Translating into</Text>
        <Text size="2" weight="bold">{langName}</Text>
        <Text size="1" color="gray" ml="auto" style={{ fontVariantNumeric: 'tabular-nums' }}>
          {done}/{items.length} up to date
        </Text>
      </Flex>
      <Table.Root variant="surface" size="2">
        <Table.Body>
          {items.map((item) => {
            const state = item.translations[langcode];
            const st = state?.status ?? 'not_translated';
            const isSource = st === 'source';
            return (
              <Table.Row
                key={`${item.kind}:${item.type}:${item.id}`}
                align="center"
                className={isSource ? undefined : 'ct-row'}
                onClick={isSource ? undefined : () => onOpen(item)}
              >
                <Table.RowHeaderCell>
                  <Flex align="center" gap="2">
                    <Text weight="medium">{item.label}</Text>
                    <Badge color="gray" variant="soft">{typeLabel(item)}</Badge>
                  </Flex>
                </Table.RowHeaderCell>
                <Table.Cell width="150px">
                  {isSource
                    ? <Text size="1" color="gray">Source language</Text>
                    : <StatusBadge status={st} draft={state?.hasDraft} changedStrings={state?.changedStrings} />}
                </Table.Cell>
                <Table.Cell width="170px">
                  {!isSource && <PercentBar percent={state?.percent ?? 0} color={statusMeta[st].color} />}
                </Table.Cell>
                <Table.Cell width="120px">
                  {/* The row is the affordance; verb + chevron surface on hover. */}
                  {!isSource && <Flex justify="end" align="center" gap="1">
                    <Text size="1" weight="medium" className="ct-go-label" style={{ color: 'var(--accent-11)' }}>
                      {st === 'not_translated' ? 'Translate' : 'Edit'}
                    </Text>
                    <ChevronRightIcon className="ct-go-icon" />
                  </Flex>}
                </Table.Cell>
              </Table.Row>
            );
          })}
        </Table.Body>
      </Table.Root>
    </Box>
  );
}

function EmptyState({ icon, title, body }: { icon: React.ReactNode; title: string; body: string }) {
  return (
    <Flex direction="column" align="center" justify="center" gap="2" py="8" px="4" className="ct-fade-in" style={{ textAlign: 'center' }}>
      <Flex align="center" justify="center" mb="1" style={{ width: 48, height: 48, borderRadius: '50%', background: 'var(--accent-3)', color: 'var(--accent-11)' }}>
        {icon}
      </Flex>
      <Heading size="3">{title}</Heading>
      <Text size="2" color="gray" style={{ maxWidth: 320 }}>{body}</Text>
    </Flex>
  );
}

function SkeletonTable() {
  return (
    <Flex direction="column" gap="2" p="4">
      {Array.from({ length: 6 }).map((_, i) => (
        <Box
          key={i}
          style={{ height: 34, borderRadius: 'var(--radius-3)', background: 'var(--gray-3)', opacity: 1 - i * 0.12 }}
        />
      ))}
    </Flex>
  );
}

function Legend() {
  return (
    <Flex gap="4" px="4" py="2" wrap="wrap" style={{ borderTop: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
      {(['up_to_date', 'outdated', 'draft', 'not_translated'] as Status[]).map((s) => (
        <Flex key={s} align="center" gap="2">
          <Box style={{ width: 8, height: 8, borderRadius: '50%', background: `var(--${statusMeta[s].color}-9)` }} />
          <Text size="1" color="gray">{statusMeta[s].label}</Text>
        </Flex>
      ))}
    </Flex>
  );
}
