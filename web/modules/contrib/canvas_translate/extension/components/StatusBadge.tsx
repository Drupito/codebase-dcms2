import { Badge, Flex, Progress, Text } from '@radix-ui/themes';
import type { Status } from '../types.ts';
import { statusMeta, type StatusColor } from '../theme.ts';

/** Completeness bar with an inline percent label, built on Radix Progress. */
export function PercentBar({ percent, color }: { percent: number; color: StatusColor }) {
  return (
    <Flex align="center" gap="2" style={{ minWidth: 96 }}>
      <Progress value={percent} color={color} size="1" style={{ flex: 1 }} />
      <Text
        size="1"
        weight="medium"
        color={percent >= 100 ? 'green' : 'gray'}
        style={{ minWidth: 30, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}
      >
        {percent}%
      </Text>
    </Flex>
  );
}

export function StatusBadge({
  status,
  draft,
  changedStrings,
}: {
  status: Status;
  draft?: boolean;
  /** How many source strings changed; shown only on an outdated badge. */
  changedStrings?: number | null;
}) {
  const meta = statusMeta[status];
  return (
    <Badge color={meta.color} variant="soft" radius="full">
      {meta.label}
      {status === 'outdated' && changedStrings != null && changedStrings > 0 && (
        <Text size="1" style={{ opacity: 0.85 }}>· {changedStrings} changed</Text>
      )}
      {draft && status !== 'draft' && (
        <Text size="1" color="gray" style={{ opacity: 0.8 }}>· editing</Text>
      )}
    </Badge>
  );
}
