<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\trash\Exception\UnrestorableEntityException;
use Drupal\trash\Hook\TrashHandler\FileTrashHandler;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Tests Trash integration with File entities.
 */
#[CoversClass(FileTrashHandler::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashFileTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'path',
    'path_alias',
  ];

  /**
   * The file system.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    $this->installEntitySchema('path_alias');

    $user = User::create(['uid' => 1, 'name' => 'test_user']);
    $user->enforceIsNew();
    $user->save();
    \Drupal::currentUser()->setAccount($user);

    $this->enableEntityTypesForTrash(['file', 'path_alias']);

    $this->fileSystem = $this->container->get('file_system');
  }

  /**
   * Tests the full public-file lifecycle: trash, restore, retrash, purge.
   */
  public function testPublicFileLifecycle(): void {
    // Trash: file moves to .trashed/<hmac>/<basename>.
    $file = $this->createPublicFile('public://documents/report.pdf', 'REPORT');
    $original_uri = $file->getFileUri();

    $file->delete();

    $trashed = $this->loadTrashedEntity('file', $file->id());
    assert($trashed instanceof FileInterface);
    $this->assertMatchesRegularExpression(
      '#^public://documents/\.trashed/[a-zA-Z0-9_-]{16}/report\.pdf$#',
      $trashed->getFileUri(),
    );
    $this->assertFileDoesNotExist($original_uri);
    $this->assertFileExists($trashed->getFileUri());
    $this->assertStringEqualsFile($trashed->getFileUri(), 'REPORT');

    // Restore: file returns to the original URI and the HMAC dir is cleaned.
    $this->restoreEntity('file', $file->id());
    $restored = File::load($file->id());
    $this->assertEquals($original_uri, $restored->getFileUri());
    $this->assertFileExists($original_uri);
    $this->assertDirectoryDoesNotExist('public://documents/.trashed');

    // Retrash: same HMAC, same trashed URI.
    $restored->delete();
    $re_trashed = $this->loadTrashedEntity('file', $file->id());
    assert($re_trashed instanceof FileInterface);
    $this->assertEquals($trashed->getFileUri(), $re_trashed->getFileUri());
    $this->assertFileExists($re_trashed->getFileUri());

    // Purge: file and the empty directories are removed.
    $this->purgeEntity('file', $file->id());
    $this->assertFileDoesNotExist($re_trashed->getFileUri());
    $this->assertDirectoryDoesNotExist('public://documents/.trashed');
    $this->assertEmpty(File::load($file->id()));

    // Stream-root file: verify the trashed URI has no leading subdir segment.
    $root_file = $this->createPublicFile('public://top.txt', 'TOP');
    $root_file->delete();
    $trashed_root = $this->loadTrashedEntity('file', $root_file->id());
    assert($trashed_root instanceof FileInterface);
    $this->assertMatchesRegularExpression(
      '#^public://\.trashed/[a-zA-Z0-9_-]{16}/top\.txt$#',
      $trashed_root->getFileUri(),
    );

    $this->restoreEntity('file', $root_file->id());
    $this->assertEquals('public://top.txt', File::load($root_file->id())->getFileUri());
    $this->assertDirectoryDoesNotExist('public://.trashed');
  }

  /**
   * Tests the trash restoration with unexpected file states.
   */
  public function testUnexpectedTrashFileStates(): void {
    $logger = new BufferingLogger();
    // File logger will be called after UnrestorableEntityException is thrown.
    $file_logger = new BufferingLogger();
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->expects($this->atLeastOnce())
      ->method('get')
      ->willReturnCallback(fn (string $channel) => match ($channel) {
        'trash' => $logger,
        'file' => $file_logger,
        default => throw new \LogicException('Unexpected channel: ' . $channel),
      });
    $this->container->set('logger.factory', $logger_factory);
    $file = $this->createPublicFile('public://report.txt', 'REPORT');
    $this->assertDirectoryDoesNotExist('public://.trashed');
    $file->delete();
    $this->assertDirectoryExists('public://.trashed');

    $trashed_file = $this->loadTrashedEntity('file', $file->id());
    assert($trashed_file instanceof FileInterface);

    // Create a stray file within the .trashed directory.
    file_put_contents('public://.trashed/extra-file.txt', 'extra');
    // Simulate a previously half-finished restoration by moving the trashed
    // file back to where it originally should be.
    $trashed_uri = $trashed_file->getFileUri();
    $this->fileSystem->move($trashed_uri, 'public://report.txt');

    // Restoration should still be possible, but with a warning in the logs.
    $this->restoreEntity('file', $file->id());
    $logs = $logger->cleanLogs();
    $this->assertCount(1, $logs);
    $this->assertEquals('warning', $logs[0][0]);
    $this->assertEquals('Adopted existing file at @original_uri (size matches entity metadata) as the restoration target; the trashed file at @trashed_uri no longer exists, likely from a previously interrupted restoration.', $logs[0][1]);
    $this->assertEquals([
      '@original_uri' => 'public://report.txt',
      '@trashed_uri' => $trashed_uri,
    ], $logs[0][2]);
    // Ensure the .trashed folder is only deleted if it's truly empty.
    $this->assertFileExists('public://.trashed/extra-file.txt');

    // Simulate a previously interrupted trash attempt: the physical file was
    // already moved to the trash target, but the entity kept its original
    // URI. A retried delete must adopt the file at the target instead of
    // orphaning it.
    $trashed_directory = dirname($trashed_uri);
    $this->fileSystem->prepareDirectory($trashed_directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->move('public://report.txt', $trashed_uri);
    File::load($file->id())->delete();
    $logs = $logger->cleanLogs();
    $this->assertCount(1, $logs);
    $this->assertEquals('warning', $logs[0][0]);
    $this->assertEquals('Adopted existing file at @target_uri (size matches entity metadata) as the trash target; the source at @source_uri no longer exists, likely from a previously interrupted trash attempt.', $logs[0][1]);
    $this->assertEquals([
      '@target_uri' => $trashed_uri,
      '@source_uri' => 'public://report.txt',
    ], $logs[0][2]);
    $adopted = $this->loadTrashedEntity('file', $file->id());
    assert($adopted instanceof FileInterface);
    $this->assertEquals($trashed_uri, $adopted->getFileUri());
    $this->assertStringEqualsFile($trashed_uri, 'REPORT');

    // Put the file back into a live state for the final scenario below.
    $this->restoreEntity('file', $file->id());
    $this->assertEquals('public://report.txt', File::load($file->id())->getFileUri());

    // A look-alike file at the trash target whose size does not match the
    // entity's metadata must not be adopted; the entity keeps its stale URI.
    $other_file = $this->createPublicFile('public://other.txt', 'OTHER');
    $other_file->delete();
    $other_trashed = $this->loadTrashedEntity('file', $other_file->id());
    assert($other_trashed instanceof FileInterface);
    $other_trashed_uri = $other_trashed->getFileUri();
    $this->restoreEntity('file', $other_file->id());
    $this->fileSystem->delete('public://other.txt');
    $other_trashed_directory = dirname($other_trashed_uri);
    $this->fileSystem->prepareDirectory($other_trashed_directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($other_trashed_uri, 'SIZE MISMATCH');
    File::load($other_file->id())->delete();
    $logs = $logger->cleanLogs();
    $this->assertCount(1, $logs);
    $this->assertEquals('error', $logs[0][0]);
    $this->assertEquals('Could not move @uri to @target on trash: @message', $logs[0][1]);
    $this->assertEquals('public://other.txt', $logs[0][2]['@uri']);
    $this->assertEquals($other_trashed_uri, $logs[0][2]['@target']);
    $other_trashed = $this->loadTrashedEntity('file', $other_file->id());
    assert($other_trashed instanceof FileInterface);
    $this->assertEquals('public://other.txt', $other_trashed->getFileUri());

    // Re-trash the restored file, then remove the trashed file too.
    $restored = $this->loadTrashedEntity('file', $file->id());
    assert($restored instanceof FileInterface);
    $restored->delete();
    $re_trashed = $this->loadTrashedEntity('file', $file->id());
    assert($re_trashed instanceof FileInterface);
    $this->fileSystem->delete($re_trashed->getFileUri());
    try {
      // With neither the trashed file nor the original URI present, the
      // restoration has nothing to adopt and must fail.
      $this->restoreEntity('file', $file->id());
      $this->fail('An UnrestorableEntityException exception should have been thrown');
    }
    catch (UnrestorableEntityException) {
    }
    $logs = $file_logger->cleanLogs();
    $this->assertCount(1, $logs);
    $this->assertEquals('error', $logs[0][0]);
    $this->assertEquals(UnrestorableEntityException::class, $logs[0][2]['%type']);
    $this->assertMatchesRegularExpression("{^File .*? could not be copied because it does not exist\.$}", $logs[0][2]['@message']);
  }

  /**
   * Tests that files on non-WRITE_VISIBLE wrappers are not relocated.
   */
  public function testNonPublicFileStaysInPlace(): void {
    $uri = 'temporary://transient.txt';
    file_put_contents($uri, 'TRANSIENT');
    $file = File::create(['uri' => $uri, 'uid' => 1]);
    $file->save();

    $file->delete();

    $trashed = $this->loadTrashedEntity('file', $file->id());
    assert($trashed instanceof FileInterface);
    $this->assertEquals($uri, $trashed->getFileUri());
    $this->assertFileExists($uri);
  }

}
