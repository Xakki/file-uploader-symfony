<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the management endpoints (restore / cleanup) through a real kernel.
 * UploadFlowTest covers upload + list + delete; this fills the remaining two.
 */
final class FileManagementTest extends KernelTestCase
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
        restore_exception_handler();
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    public function test_restore_endpoint_brings_back_a_trashed_file(): void
    {
        $root = $this->seedTrashed('keep.txt', 'restore me', '2026-01-02T00:00:00+00:00');
        $hash = hash('sha256', 'restore me');

        $result = $this->json(self::$kernel->handle(
            Request::create('/file-upload/files/'.$hash.'/restore', 'POST'),
        ));

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertFileExists($root.'/keep.txt');
        self::assertFileDoesNotExist($root.'/.trash/keep.txt');
    }

    public function test_cleanup_endpoint_removes_expired_trash(): void
    {
        $root = $this->seedTrashed('old.txt', 'purge me', '2020-01-02T00:00:00+00:00');
        $hash = hash('sha256', 'purge me');

        $result = $this->json(self::$kernel->handle(
            Request::create('/file-upload/trash/cleanup', 'DELETE'),
        ));

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame(1, $result['json']['data']['count']);
        self::assertFileDoesNotExist($root.'/.trash/old.txt');
        self::assertFileDoesNotExist($root.'/.meta/'.$hash.'.json');
    }

    private function seedTrashed(string $name, string $content, string $deletedAt): string
    {
        self::bootKernel();
        self::assertInstanceOf(TestKernel::class, self::$kernel);
        $root = self::$kernel->uploadRoot();
        $hash = hash('sha256', $content);

        (new Filesystem)->mkdir([$root.'/.trash', $root.'/.meta']);
        file_put_contents($root.'/.trash/'.$name, $content);
        file_put_contents($root.'/.meta/'.$hash.'.json', (string) json_encode([
            'id' => $hash,
            'name' => $name,
            'size' => strlen($content),
            'mime' => 'text/plain',
            'path' => $name,
            'disk' => 'default',
            'hash' => $hash,
            'createdAt' => '2020-01-01T00:00:00+00:00',
            'deletedAt' => $deletedAt,
            'trashPath' => '.trash/'.$name,
            'url' => null,
            'userId' => null,
        ], JSON_PRETTY_PRINT));

        return $root;
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function json(Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'json' => json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
