import { describe, it, expect } from 'vitest';
import { wordDiff, type DiffSeg } from './diff.ts';

/** Reconstruct the new string from a diff (add + same segments). */
function fromNew(segs: DiffSeg[]): string {
  return segs.filter((s) => s.type !== 'del').map((s) => s.text).join('');
}

/** Reconstruct the old string from a diff (del + same segments). */
function fromOld(segs: DiffSeg[]): string {
  return segs.filter((s) => s.type !== 'add').map((s) => s.text).join('');
}

describe('wordDiff', () => {
  it('identical strings → all segments are "same"', () => {
    const segs = wordDiff('foo bar baz', 'foo bar baz');
    expect(segs.every((s) => s.type === 'same')).toBe(true);
    expect(fromNew(segs)).toBe('foo bar baz');
    expect(fromOld(segs)).toBe('foo bar baz');
  });

  it('identical single word → one same segment', () => {
    const segs = wordDiff('hello', 'hello');
    expect(segs).toEqual([{ text: 'hello', type: 'same' }]);
  });

  it('pure insertion (words appended) → no del segments', () => {
    const segs = wordDiff('hello', 'hello world');
    expect(segs.some((s) => s.type === 'del')).toBe(false);
    expect(segs.some((s) => s.type === 'add')).toBe(true);
    expect(fromNew(segs)).toBe('hello world');
    expect(fromOld(segs)).toBe('hello');
  });

  it('pure deletion → no add segments', () => {
    const segs = wordDiff('hello world', 'hello');
    expect(segs.some((s) => s.type === 'add')).toBe(false);
    expect(segs.some((s) => s.type === 'del')).toBe(true);
    expect(fromNew(segs)).toBe('hello');
    expect(fromOld(segs)).toBe('hello world');
  });

  it('word substitution → both del and add present', () => {
    const segs = wordDiff('foo bar', 'foo baz');
    expect(segs.some((s) => s.type === 'del')).toBe(true);
    expect(segs.some((s) => s.type === 'add')).toBe(true);
    expect(fromNew(segs)).toBe('foo baz');
    expect(fromOld(segs)).toBe('foo bar');
  });

  it('whitespace runs are preserved in reconstruction', () => {
    const oldStr = 'a b c';
    const newStr = 'a x c';
    const segs = wordDiff(oldStr, newStr);
    expect(fromNew(segs)).toBe(newStr);
    expect(fromOld(segs)).toBe(oldStr);
  });

  it('reconstruction invariant holds for multi-word changes', () => {
    const oldStr = 'the quick brown fox';
    const newStr = 'the slow red fox';
    const segs = wordDiff(oldStr, newStr);
    expect(fromNew(segs)).toBe(newStr);
    expect(fromOld(segs)).toBe(oldStr);
  });

  it('empty → non-empty: reconstruction holds', () => {
    const segs = wordDiff('', 'hello world');
    expect(fromNew(segs)).toBe('hello world');
    expect(fromOld(segs)).toBe('');
  });

  it('non-empty → empty: reconstruction holds', () => {
    const segs = wordDiff('hello world', '');
    expect(fromNew(segs)).toBe('');
    expect(fromOld(segs)).toBe('hello world');
  });

  it('both empty → returns segments reconstructing empty strings', () => {
    const segs = wordDiff('', '');
    expect(fromNew(segs)).toBe('');
    expect(fromOld(segs)).toBe('');
  });
});
