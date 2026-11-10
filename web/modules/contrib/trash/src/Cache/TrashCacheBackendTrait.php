<?php

declare(strict_types=1);

namespace Drupal\trash\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\trash\Trash;

/**
 * Provides cache backend decorator methods for filtering deleted entities.
 *
 * @property \Drupal\Core\Cache\CacheBackendInterface $inner
 */
trait TrashCacheBackendTrait {

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->inner->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return $this->inner->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
    if ($data instanceof FieldableEntityInterface && Trash::entityIsDeleted($data)) {
      return;
    }
    $this->inner->set($cid, $data, $expire, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      if (isset($item['data'])
          && $item['data'] instanceof FieldableEntityInterface
          && Trash::entityIsDeleted($item['data'])) {
        unset($items[$cid]);
      }
    }
    if ($items) {
      $this->inner->setMultiple($items);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->inner->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    $this->inner->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->inner->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->inner->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->inner->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    // Deprecated in core 11.2 and removed in 12.0, so the decorated backend is
    // not guaranteed to have it.
    // @see https://www.drupal.org/node/3500622
    if (method_exists($this->inner, 'invalidateAll')) {
      $this->inner->invalidateAll();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->inner->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->inner->removeBin();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    if ($this->inner instanceof CacheTagsInvalidatorInterface) {
      $this->inner->invalidateTags($tags);
    }
  }

  /**
   * Reset statically cached variables.
   */
  public function reset(...$args) {
    if (method_exists($this->inner, 'reset')) {
      return $this->inner->reset(...$args);
    }
  }

}
