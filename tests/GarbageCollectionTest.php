<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Proves `file-uploader:cleanup` performs age-based GC beyond the core trash sweep:
 * abandoned ACTIVE files (opt-in via active_ttl_days) and incomplete chunk dirs.
 */
final class GarbageCollectionTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        TestKernel::$activeTtlDays = null;
        TestKernel::$chunkTtlDays = 0;
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
        TestKernel::$activeTtlDays = null;
        TestKernel::$chunkTtlDays = 0;
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    public function test_cleanup_purges_abandoned_active_file_older_than_ttl(): void
    {
        TestKernel::$activeTtlDays = 1;
        $root = $this->bootRoot();
        ['blob' => $blob, 'meta' => $meta, 'hash' => $hash] =
            $this->writeActiveFile($root, 'abandoned', '2020-01-01T00:00:00+00:00');

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed 1 abandoned active file(s).', $tester->getDisplay());
        self::assertFileDoesNotExist($blob);
        self::assertFileDoesNotExist($meta);
    }

    public function test_cleanup_keeps_recent_active_file(): void
    {
        TestKernel::$activeTtlDays = 1;
        $root = $this->bootRoot();
        ['blob' => $blob, 'meta' => $meta] =
            $this->writeActiveFile($root, 'fresh', (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed 0 abandoned active file(s).', $tester->getDisplay());
        self::assertFileExists($blob);
        self::assertFileExists($meta);
    }

    public function test_active_expiry_is_disabled_by_default(): void
    {
        // active_ttl_days stays null (the default) -> no active expiry, fully BC.
        $root = $this->bootRoot();
        ['blob' => $blob, 'meta' => $meta] =
            $this->writeActiveFile($root, 'kept', '2020-01-01T00:00:00+00:00');

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('abandoned active', $tester->getDisplay());
        self::assertFileExists($blob);
        self::assertFileExists($meta);
    }

    public function test_cleanup_removes_stale_chunk_directory(): void
    {
        TestKernel::$chunkTtlDays = 1;
        $root = $this->bootRoot();
        $dir = $root.'/.chunks/upload-stale';
        (new Filesystem)->mkdir($dir);
        file_put_contents($dir.'/0', 'partial');
        touch($dir.'/0', time() - 2 * 86400);

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed 1 stale chunk directory(ies).', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($dir);
    }

    public function test_cleanup_keeps_fresh_chunk_directory(): void
    {
        TestKernel::$chunkTtlDays = 1;
        $root = $this->bootRoot();
        $dir = $root.'/.chunks/upload-fresh';
        (new Filesystem)->mkdir($dir);
        file_put_contents($dir.'/0', 'partial');

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed 0 stale chunk directory(ies).', $tester->getDisplay());
        self::assertDirectoryExists($dir);
    }

    public function test_chunk_cleanup_is_disabled_by_default(): void
    {
        // chunk_ttl_days stays 0 (the default) -> no chunk GC, fully BC.
        $root = $this->bootRoot();
        $dir = $root.'/.chunks/upload-old';
        (new Filesystem)->mkdir($dir);
        file_put_contents($dir.'/0', 'partial');
        touch($dir.'/0', time() - 5 * 86400);

        $tester = $this->runCleanup();

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('stale chunk', $tester->getDisplay());
        self::assertDirectoryExists($dir);
    }

    /**
     * @return array{blob: string, meta: string, hash: string}
     */
    private function writeActiveFile(string $root, string $content, string $createdAt): array
    {
        $hash = hash('sha256', $content);
        $name = $hash.'.txt';
        (new Filesystem)->mkdir([$root, $root.'/.meta']);
        file_put_contents($root.'/'.$name, $content);
        file_put_contents($root.'/.meta/'.$hash.'.json', (string) json_encode([
            'id' => $hash,
            'name' => $name,
            'size' => strlen($content),
            'mime' => 'text/plain',
            'path' => $name,
            'disk' => 'default',
            'hash' => $hash,
            'createdAt' => $createdAt,
            'deletedAt' => null,
            'trashPath' => null,
            'url' => null,
            'userId' => null,
        ], JSON_PRETTY_PRINT));

        return [
            'blob' => $root.'/'.$name,
            'meta' => $root.'/.meta/'.$hash.'.json',
            'hash' => $hash,
        ];
    }

    private function bootRoot(): string
    {
        self::bootKernel();
        self::assertInstanceOf(TestKernel::class, self::$kernel);

        return self::$kernel->uploadRoot();
    }

    private function runCleanup(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('file-uploader:cleanup'));
        $tester->execute([]);

        return $tester;
    }
}
