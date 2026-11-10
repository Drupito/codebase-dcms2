import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, Box, Button, Checkbox, Flex, Heading, Table, Text } from '@radix-ui/themes';
import { ArrowLeftIcon, CheckCircledIcon, CheckIcon } from '@radix-ui/react-icons';
import {
  ApiError,
  discardConfigTranslation,
  discardDraft,
  fetchItems,
  publishBatch,
  publishConfigTranslation,
} from '../api.ts';
import type { ConfigType, DashboardResponse } from '../types.ts';
import { PercentBar } from './StatusBadge.tsx';
import { Banner } from './Banner.tsx';
import { type DraftRow, draftRowKey, flattenDrafts, reviewPath } from '../drafts.ts';

/**
 * Canvas-style "Review & publish": lists every pending translation draft across
 * all pages, layouts and languages, and publishes a selected set.
 *
 * The list is derived from the same dashboard data as the home view. Content and
 * config drafts both appear; content publishes via the batch endpoint (one
 * revision per page), config publishes per item (Canvas's batch publish cannot
 * flush a translation-only override).
 */
export default function ReviewPanel() {
  const navigate = useNavigate();
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [publishing, setPublishing] = useState(false);
  const [loading, setLoading] = useState(true);
  // The row key currently asking to confirm a discard (inline), or null.
  const [confirmDiscard, setConfirmDiscard] = useState<string | null>(null);

  const rowKey = draftRowKey;

  const load = useCallback(() => {
    setLoading(true);
    return fetchItems()
      .then((res) => {
        setData(res);
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : String(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const rows = useMemo<DraftRow[]>(() => flattenDrafts(data), [data]);

  // Pre-select the approved drafts once the list (re)loads, so "Publish
  // selected" defaults to "publish what's been reviewed". Nothing approved yet →
  // nothing checked, nudging review-first; "Publish all" remains the override.
  useEffect(() => {
    setSelected(new Set(rows.filter((r) => r.approved).map(rowKey)));
  }, [rows]);

  const toggle = useCallback((key: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(key) ? next.delete(key) : next.add(key);
      return next;
    });
  }, []);

  const doPublish = useCallback(
    async (which: DraftRow[]) => {
      if (which.length === 0) return;
      setPublishing(true);
      setError(null);
      try {
        // Content publishes in one batch (one revision per page); config has no
        // batch endpoint, so publish each config draft individually.
        const content = which.filter((r) => r.kind === 'content');
        const config = which.filter((r) => r.kind === 'config');
        if (content.length > 0) {
          await publishBatch(content.map((r) => ({ id: Number(r.id), langcode: r.langcode })));
        }
        await Promise.all(
          config.map((r) => publishConfigTranslation(r.type as ConfigType, String(r.id), r.langcode, {})),
        );
        await load();
      } catch (err: unknown) {
        if (err instanceof ApiError) {
          const details = Array.isArray((err.details as { details?: unknown[] })?.details)
            ? (err.details as { details: Array<{ detail?: string }> }).details
                .map((d) => d.detail)
                .filter(Boolean)
                .join(' ')
            : '';
          setError(details ? `${err.message} ${details}` : err.message);
        } else {
          setError(String(err));
        }
      } finally {
        setPublishing(false);
      }
    },
    [load],
  );

  const onDiscard = useCallback(
    async (r: DraftRow) => {
      setError(null);
      try {
        if (r.kind === 'config') {
          await discardConfigTranslation(r.type as ConfigType, String(r.id), r.langcode);
        } else {
          await discardDraft(Number(r.id), r.langcode);
        }
        await load();
      } catch (err: unknown) {
        setError(err instanceof ApiError ? err.message : String(err));
      }
    },
    [load],
  );

  const selectedRows = rows.filter((r) => selected.has(rowKey(r)));
  const approvedCount = rows.filter((r) => r.approved).length;

  return (
    <Flex direction="column" height="100%" overflow="hidden">
      <Box px="4" py="3" style={{ borderBottom: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
        <Flex align="center" gap="3" wrap="wrap">
          <Button variant="soft" color="gray" onClick={() => navigate('/')}>
            <ArrowLeftIcon />
            All pages
          </Button>
          <Box>
            <Heading size="5" weight="bold">Review &amp; publish</Heading>
            <Text size="2" color="gray">
              {rows.length} pending draft{rows.length === 1 ? '' : 's'}
              {approvedCount > 0 ? ` · ${approvedCount} approved` : ''}
            </Text>
          </Box>
          <Flex gap="3" ml="auto">
            <Button variant="soft" color="gray" onClick={() => doPublish(rows)} disabled={publishing || rows.length === 0}>
              Publish all
            </Button>
            <Button
              color="green"
              onClick={() => doPublish(selectedRows)}
              disabled={publishing || selectedRows.length === 0}
              loading={publishing}
            >
              Publish selected ({selectedRows.length})
            </Button>
          </Flex>
        </Flex>
        {error && <Box mt="3"><Banner tone="error">{error}</Banner></Box>}
      </Box>

      <Box flexGrow="1" overflow="auto">
        {loading && !data && <Box p="4"><Banner tone="neutral">Loading…</Banner></Box>}
        {data && rows.length === 0 && !loading && (
          <Flex direction="column" align="center" justify="center" gap="2" py="8" px="4" className="ct-fade-in" style={{ textAlign: 'center' }}>
            <Flex align="center" justify="center" mb="1" style={{ width: 48, height: 48, borderRadius: '50%', background: 'var(--green-3)', color: 'var(--green-11)' }}>
              <CheckCircledIcon width="22" height="22" />
            </Flex>
            <Heading size="3">All caught up</Heading>
            <Text size="2" color="gray" style={{ maxWidth: 320 }}>No pending drafts. Every translation is published.</Text>
          </Flex>
        )}
        {rows.length > 0 && (
          <Table.Root variant="surface" size="1" m="4">
            <Table.Header>
              <Table.Row>
                <Table.ColumnHeaderCell width="44px" />
                <Table.ColumnHeaderCell>Item</Table.ColumnHeaderCell>
                <Table.ColumnHeaderCell width="140px">Language</Table.ColumnHeaderCell>
                <Table.ColumnHeaderCell width="160px">Complete</Table.ColumnHeaderCell>
                <Table.ColumnHeaderCell width="130px">Status</Table.ColumnHeaderCell>
                <Table.ColumnHeaderCell width="210px" />
              </Table.Row>
            </Table.Header>
            <Table.Body>
              {rows.map((r) => {
                const key = rowKey(r);
                return (
                  <Table.Row key={key} align="center">
                    <Table.Cell>
                      <Checkbox checked={selected.has(key)} onCheckedChange={() => toggle(key)} />
                    </Table.Cell>
                    <Table.RowHeaderCell>
                      <Text
                        weight="medium"
                        onClick={() => navigate(reviewPath(r))}
                        style={{ cursor: 'pointer' }}
                        title="Review this translation"
                      >
                        {r.label}
                      </Text>
                    </Table.RowHeaderCell>
                    <Table.Cell><Text color="gray">{r.langName}</Text></Table.Cell>
                    <Table.Cell><PercentBar percent={r.percent} color="blue" /></Table.Cell>
                    <Table.Cell>
                      {r.approved ? (
                        <Badge color="green" variant="soft" radius="full"><CheckIcon width="12" height="12" /> Approved</Badge>
                      ) : (
                        <Badge color="gray" variant="soft" radius="full">Needs review</Badge>
                      )}
                    </Table.Cell>
                    <Table.Cell>
                      {confirmDiscard === key ? (
                        <Flex gap="2" justify="end" align="center">
                          <Text size="1" color="gray">Discard?</Text>
                          <Button variant="solid" color="red" size="1" onClick={() => { setConfirmDiscard(null); onDiscard(r); }}>Discard</Button>
                          <Button variant="soft" color="gray" size="1" onClick={() => setConfirmDiscard(null)}>Cancel</Button>
                        </Flex>
                      ) : (
                        <Flex gap="3" justify="end" align="center">
                          <Button variant="soft" color="gray" size="1" onClick={() => navigate(reviewPath(r))}>Review</Button>
                          <Button variant="ghost" color="gray" size="1" onClick={() => setConfirmDiscard(key)}>Discard</Button>
                        </Flex>
                      )}
                    </Table.Cell>
                  </Table.Row>
                );
              })}
            </Table.Body>
          </Table.Root>
        )}
      </Box>
    </Flex>
  );
}
