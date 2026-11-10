import type { Status } from './types.ts';

/**
 * Translation status as a Radix accent color, so badges and progress bars use
 * the same scales as the rest of Canvas. The colors read as a semantic ramp:
 * gray (untouched) → blue (in progress) → amber (needs attention) → green (done).
 */
export type StatusColor = 'gray' | 'blue' | 'amber' | 'green';

export const statusMeta: Record<Status, { label: string; color: StatusColor }> = {
  source: { label: 'Source', color: 'gray' },
  not_translated: { label: 'Not translated', color: 'gray' },
  draft: { label: 'Draft', color: 'blue' },
  outdated: { label: 'Outdated', color: 'amber' },
  up_to_date: { label: 'Up to date', color: 'green' },
};
