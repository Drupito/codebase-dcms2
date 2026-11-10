export type DiffSeg = { text: string; type: 'same' | 'add' | 'del' };

/**
 * Word-level diff of two strings via LCS, splitting on whitespace (runs of
 * spaces are kept as tokens so spacing survives). Returns segments tagged as
 * unchanged / added (in the new text) / removed (from the old text).
 *
 * ponytail: O(old × new) word LCS — fine for field-length strings; chunk if a
 * field ever holds many KB of prose.
 */
export function wordDiff(oldStr: string, newStr: string): DiffSeg[] {
  const a = oldStr.split(/(\s+)/);
  const b = newStr.split(/(\s+)/);
  const m = a.length;
  const n = b.length;
  const dp: number[][] = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
  for (let i = m - 1; i >= 0; i--) {
    for (let j = n - 1; j >= 0; j--) {
      dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  const segs: DiffSeg[] = [];
  let i = 0;
  let j = 0;
  while (i < m && j < n) {
    if (a[i] === b[j]) {
      segs.push({ text: a[i], type: 'same' });
      i++;
      j++;
    } else if (dp[i + 1][j] >= dp[i][j + 1]) {
      segs.push({ text: a[i], type: 'del' });
      i++;
    } else {
      segs.push({ text: b[j], type: 'add' });
      j++;
    }
  }
  while (i < m) segs.push({ text: a[i++], type: 'del' });
  while (j < n) segs.push({ text: b[j++], type: 'add' });
  return segs;
}
