import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Box, Button, Flex, Heading, Table, Text } from '@radix-ui/themes';
import { ArrowLeftIcon, ChevronRightIcon } from '@radix-ui/react-icons';
import { ApiError, fetchItems } from '../api.ts';
import type { DashboardResponse } from '../types.ts';
import { statusMeta } from '../theme.ts';
import { PercentBar, StatusBadge } from './StatusBadge.tsx';
import { Banner } from './Banner.tsx';

/**
 * Every language of one page: where core's "Translate" operation and tab land.
 *
 * @see \Drupal\canvas_translate\Controller\TranslateRedirectController
 */
export default function PageOverview() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchItems({ id: Number(id) })
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : String(err));
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  const item = data?.items[0];
  const languages = (data?.languages ?? []).filter((l) => l.id !== item?.sourceLangcode);
  const sourceName = data?.languages.find((l) => l.id === item?.sourceLangcode)?.name;

  return (
    <Flex direction="column" height="100%" overflow="hidden">
      <style>{`
        .ct-row { cursor: pointer; transition: background 0.12s ease; }
        .ct-row:hover { background: var(--gray-3); }
        .ct-go-icon { color: var(--gray-8); transition: color 0.12s ease, transform 0.12s ease; }
        .ct-go-label { opacity: 0; transition: opacity 0.12s ease; }
        .ct-row:hover .ct-go-icon { color: var(--accent-11); transform: translateX(2px); }
        .ct-row:hover .ct-go-label { opacity: 1; }
      `}</style>

      <Box px="4" py="3" style={{ borderBottom: '1px solid var(--gray-5)', background: 'var(--color-panel-solid)' }}>
        <Flex align="center" gap="3">
          <Button variant="soft" color="gray" onClick={() => navigate('/')} title="Back to all items">
            <ArrowLeftIcon />
            All pages
          </Button>
          {item && (
            <Box style={{ minWidth: 0 }}>
              <Heading size="3" weight="bold" truncate style={{ maxWidth: 320 }}>{item.label}</Heading>
              {sourceName && <Text as="p" size="1" color="gray" mt="1">Source: {sourceName}</Text>}
            </Box>
          )}
        </Flex>
      </Box>

      <Box flexGrow="1" overflow="auto" p="4">
        {error && <Banner tone="error">{error}</Banner>}
        {data && !item && (
          <Banner tone="error">This page doesn’t exist, or you can’t translate it.</Banner>
        )}
        {item && (
          <Table.Root variant="surface" size="2" className="ct-fade-in">
            <Table.Body>
              {languages.map((l) => {
                const state = item.translations[l.id];
                const st = state?.status ?? 'not_translated';
                const open = () => navigate(`/page/${item.id}/${l.id}`);
                return (
                  <Table.Row
                    key={l.id}
                    align="center"
                    className="ct-row"
                    role="link"
                    tabIndex={0}
                    onClick={open}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') open();
                    }}
                  >
                    <Table.RowHeaderCell>
                      <Text weight="medium">{l.name}</Text>
                    </Table.RowHeaderCell>
                    <Table.Cell width="150px">
                      <StatusBadge status={st} draft={state?.hasDraft} changedStrings={state?.changedStrings} />
                    </Table.Cell>
                    <Table.Cell width="170px">
                      <PercentBar percent={state?.percent ?? 0} color={statusMeta[st].color} />
                    </Table.Cell>
                    <Table.Cell width="120px">
                      <Flex justify="end" align="center" gap="1">
                        <Text size="1" weight="medium" className="ct-go-label" style={{ color: 'var(--accent-11)' }}>
                          {st === 'not_translated' ? 'Translate' : 'Edit'}
                        </Text>
                        <ChevronRightIcon className="ct-go-icon" />
                      </Flex>
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
