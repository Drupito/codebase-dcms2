<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\trash\Handler\DefaultTrashHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that restoring an entity restores the files trashed together with it.
 */
#[CoversClass(DefaultTrashHandler::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashFileIntegrationTest extends TrashKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'language',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = [
    'file' => [],
    'media' => [],
  ];

  /**
   * The name of the source field of the test media type.
   */
  protected string $sourceField;

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $media_type = $this->createMediaType('file', ['id' => 'document']);
    $this->sourceField = $media_type->getSource()
      ->getSourceFieldDefinition($media_type)
      ->getName();
  }

  /**
   * Tests that a file trashed together with its media comes back on restore.
   */
  public function testRestoreBringsBackCoTrashedFile(): void {
    $file = $this->createPublicFile('public://media-files/report.pdf', 'REPORT');
    $original_uri = $file->getFileUri();
    $media = $this->createMedia($file);

    // A second media item sharing the file, so the shared file is restored
    // once and the second restore finds it already live.
    $other_media = $this->createMedia($file);

    $file->delete();
    $media->delete();
    $other_media->delete();
    $this->assertEmpty(Media::load($media->id()));
    $this->assertEmpty(File::load($file->id()));

    $trashed_media = $this->loadTrashedEntity('media', $media->id());
    $trashed_file = $this->loadTrashedEntity('file', $file->id());
    assert($trashed_file instanceof FileInterface);
    $this->assertSame($trashed_media->get('deleted')->value, $trashed_file->get('deleted')->value);
    $this->assertFileDoesNotExist($original_uri);
    $this->assertFileExists($trashed_file->getFileUri());

    $this->restoreEntity('media', $media->id());
    $this->assertNotEmpty(Media::load($media->id()));
    $restored_file = File::load($file->id());
    $this->assertNotEmpty($restored_file);
    $this->assertNull($restored_file->get('deleted')->value);
    $this->assertSame($original_uri, $restored_file->getFileUri());
    $this->assertFileExists($original_uri);
    $this->assertDirectoryDoesNotExist('public://media-files/.trashed');

    $this->restoreEntity('media', $other_media->id());
    $this->assertNotEmpty(Media::load($other_media->id()));
    $this->assertNotEmpty(File::load($file->id()));
  }

  /**
   * Tests that files not trashed together with the entity stay in the trash.
   */
  public function testUnrelatedFilesStayTrashed(): void {
    // A file trashed on its own, before the media, keeps its own timestamp.
    $file = $this->createPublicFile('public://independent.txt', 'INDEPENDENT');
    $media = $this->createMedia($file);

    $file->delete();
    \Drupal::keyValue('trash_test')->set('time_offset', 100);
    $media->delete();
    \Drupal::keyValue('trash_test')->set('time_offset', 0);

    $this->assertNotSame(
      $this->loadTrashedEntity('media', $media->id())->get('deleted')->value,
      $this->loadTrashedEntity('file', $file->id())->get('deleted')->value,
    );

    $this->restoreEntity('media', $media->id());
    $this->assertNotEmpty(Media::load($media->id()));
    $this->assertEmpty(File::load($file->id()));

    // With the file entity type no longer enabled for trash, restore leaves
    // files alone and does not error out.
    $this->disableEntityTypesForTrash(['file']);
    $file = $this->createPublicFile('public://no-trash.txt', 'CONTENT');
    $media = $this->createMedia($file);
    $media->delete();
    $this->assertEmpty(Media::load($media->id()));
    $this->assertNotEmpty(File::load($file->id()));

    $this->restoreEntity('media', $media->id());
    $this->assertNotEmpty(Media::load($media->id()));
    $this->assertNotEmpty(File::load($file->id()));
  }

  /**
   * Tests that files are paired with the translation that references them.
   */
  public function testTranslationFilesFollowTheirTranslation(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $en_file = $this->createPublicFile('public://en.txt', 'EN');
    $fr_file = $this->createPublicFile('public://fr.txt', 'FR');
    $media = $this->createMedia($en_file);
    $media->addTranslation('fr', [
      'name' => 'FR',
      $this->sourceField => ['target_id' => $fr_file->id()],
    ]);
    $media->save();
    $media_id = $media->id();

    // Removing a translation trashes only that translation. Trashing its file
    // in the same request pairs the two, as media_file_delete does.
    $media = Media::load($media_id);
    $media->removeTranslation('fr');
    $media->save();
    $fr_file->delete();
    $this->assertNotEmpty(Media::load($media_id));
    $this->assertNotEmpty(File::load($en_file->id()));
    $this->assertEmpty(File::load($fr_file->id()));

    $this->restoreEntity('media', $media_id, ['fr']);
    $media = Media::load($media_id);
    $this->assertTrue($media->hasTranslation('fr'));
    $this->assertNotEmpty(File::load($fr_file->id()));
    $this->assertNotEmpty(File::load($en_file->id()));

    // Trash everything, then restore one translation at a time. Each restore
    // brings back only the file of the restored translation.
    $media->delete();
    File::load($en_file->id())->delete();
    File::load($fr_file->id())->delete();
    $this->assertEmpty(Media::load($media_id));
    $this->assertEmpty(File::load($en_file->id()));
    $this->assertEmpty(File::load($fr_file->id()));

    $this->restoreEntity('media', $media_id, ['en']);
    $media = Media::load($media_id);
    $this->assertNotEmpty($media);
    $this->assertNotEmpty($media->getTranslation('fr')->get('deleted')->value);
    $this->assertNotEmpty(File::load($en_file->id()));
    $this->assertEmpty(File::load($fr_file->id()));

    $this->restoreEntity('media', $media_id, ['fr']);
    $this->assertNotEmpty(File::load($fr_file->id()));
  }

  /**
   * Tests that any entity type with a file field gets the same treatment.
   */
  public function testNodeFileField(): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_attachment',
      'type' => 'file',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_attachment',
      'bundle' => 'article',
    ])->save();

    $file = $this->createPublicFile('public://attachment.txt', 'ATTACHMENT');
    $node = $this->createNode([
      'type' => 'article',
      'field_attachment' => ['target_id' => $file->id()],
    ]);

    $file->delete();
    $node->delete();
    $this->assertEmpty(Node::load($node->id()));
    $this->assertEmpty(File::load($file->id()));

    $this->restoreEntity('node', $node->id());
    $this->assertNotEmpty(Node::load($node->id()));
    $this->assertNotEmpty(File::load($file->id()));
  }

  /**
   * Creates a media item of the test type that references the given file.
   */
  protected function createMedia(FileInterface $file): MediaInterface {
    $media = Media::create([
      'bundle' => 'document',
      'name' => 'Test Document',
      $this->sourceField => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
