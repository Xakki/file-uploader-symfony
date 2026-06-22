<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots a real kernel and drives the bundle's console commands through a
 * CommandTester. The command bodies are thin; this proves they are registered as
 * console services and delegate to the core FileManager over the wired storage.
 */
final class CommandTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Symfony installs an exception handler while booting and does not release it
        // on shutdown; restore it so PHPUnit does not flag the test as risky.
        restore_exception_handler();
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    public function test_sync_metadata_command_creates_metadata_for_orphan_files(): void
    {
        $root = $this->bootAndUploadRoot();
        $content = 'hello world';
        (new Filesystem)->mkdir($root);
        file_put_contents($root.'/hello.txt', $content);

        $tester = $this->runUploaderCommand('file-uploader:sync-metadata');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Created: 1', $tester->getDisplay());
        self::assertFileExists($root.'/.meta/'.hash('sha256', $content).'.json');
    }

    public function test_cleanup_command_removes_expired_trash(): void
    {
        $root = $this->bootAndUploadRoot();
        $content = 'trashed';
        $hash = hash('sha256', $content);

        (new Filesystem)->mkdir([$root.'/.trash', $root.'/.meta']);
        file_put_contents($root.'/.trash/old.txt', $content);
        file_put_contents($root.'/.meta/'.$hash.'.json', (string) json_encode([
            'id' => $hash,
            'name' => 'old.txt',
            'size' => strlen($content),
            'mime' => 'text/plain',
            'path' => null,
            'disk' => 'default',
            'hash' => $hash,
            'createdAt' => '2020-01-01T00:00:00+00:00',
            'deletedAt' => '2020-01-02T00:00:00+00:00',
            'trashPath' => '.trash/old.txt',
            'url' => null,
            'userId' => null,
        ], JSON_PRETTY_PRINT));

        $tester = $this->runUploaderCommand('file-uploader:cleanup');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed 1', $tester->getDisplay());
        self::assertFileDoesNotExist($root.'/.trash/old.txt');
        self::assertFileDoesNotExist($root.'/.meta/'.$hash.'.json');
    }

    private function bootAndUploadRoot(): string
    {
        self::bootKernel();
        self::assertInstanceOf(TestKernel::class, self::$kernel);

        return self::$kernel->uploadRoot();
    }

    private function runUploaderCommand(string $name): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find($name));
        $tester->execute([]);

        return $tester;
    }
}
