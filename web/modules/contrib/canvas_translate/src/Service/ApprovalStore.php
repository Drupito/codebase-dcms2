<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Tracks which pending translation drafts a reviewer has approved.
 *
 * Approval is the middle step of the review pipeline: a reviewer reads a draft
 * (the diff against what is published), approves it, and the approved drafts
 * are later published together from the queue. The approved set is just a flag
 * per draft, keyed identically to the draft store ("{subject}:{langcode}", see
 * \Drupal\canvas_translate\Service\TranslationDraftStore::key) so the two line
 * up one-to-one. An approval is cleared whenever the draft it refers to
 * changes, is discarded, or is published — a re-edited draft must be reviewed
 * again.
 */
final class ApprovalStore {

  public function __construct(
    private readonly KeyValueStoreInterface $store,
  ) {}

  /**
   * Whether this draft has been approved.
   */
  public function isApproved(string $key): bool {
    return $this->store->has($key);
  }

  /**
   * Marks a draft approved.
   */
  public function approve(string $key): void {
    $this->store->set($key, TRUE);
  }

  /**
   * Clears a draft's approval (no-op when not approved).
   */
  public function clear(string $key): void {
    $this->store->delete($key);
  }

  /**
   * Every approved key currently stored, for O(1) dashboard lookup.
   *
   * @return array<string, true>
   *   Keyed by approval key.
   */
  public function allKeys(): array {
    return \array_fill_keys(\array_keys($this->store->getAll()), TRUE);
  }

}
