// cspell:ignore brouillon

/**
 * Stable fixture values the specs assert on. These mirror the seeding in
 * \Drupal\Tests\canvas_translate\TestSite\CanvasTranslateTestSetup, which runs
 * via test-site.php --setup-file (see fixture.ts). Keep them in sync with that
 * PHP class.
 */
export const LANGCODE = 'fr';
export const RICH_PAGE_TITLE = 'E2E Rich Text Page';
export const FORMAT_RICH_PAGE_TITLE = 'E2E Format Rich Text Page';
export const INLINE_RICH_PAGE_TITLE = 'E2E Inline Rich Text Page';
export const PLAIN_PAGE_TITLE = 'E2E Translate Page';
export const PLAIN_PAGE_DESCRIPTION = 'E2E source description for the translate test.';
export const OUTDATED_PAGE_TITLE = 'E2E Outdated Page';
export const REVIEW_PAGE_TITLE = 'E2E Review Page';
export const REVIEW_DRAFT_TITLE = 'Revue E2E (brouillon)';
export const RICH_SOURCE_HTML = '<p>Hello <strong>world</strong></p>';
export const FORMAT_RICH_SOURCE_HTML =
  '<h2>Heading</h2><p><a href="/node/1" data-entity-type="node" data-entity-uuid="5f3b9a3e-0c2d-4f6a-9b1e-2d7c8e9f0a1b">Link</a> to <em lang="de">Welt</em></p>' +
  '<drupal-media data-entity-type="media" data-entity-uuid="7a1c2e3f-4b5d-4e6f-8a9b-0c1d2e3f4a5b">&nbsp;</drupal-media>';
