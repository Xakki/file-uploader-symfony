<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xakki\SymfonyFileUploader\Service\FileWidgetRenderer;

/**
 * Boots a real kernel and drives HTTP requests through it. The core's behaviour is
 * unit-tested in the core package; this only asserts the Symfony seams are wired: the
 * ChunkPayload adapter, the Flysystem storage, routing and the response envelope.
 */
final class UploadFlowTest extends KernelTestCase
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
        parent::tearDown(); // shuts the kernel down
        // Symfony registers an exception handler while booting and does not release
        // it on shutdown; restore it so PHPUnit does not flag the test as risky.
        restore_exception_handler();
        (new Filesystem)->remove(sys_get_temp_dir().'/xakki_fu_symfony_test');
    }

    public function test_two_chunk_upload_then_list(): void
    {
        self::bootKernel();

        $uploadId = 'upload-1700000000000-abcd1234';
        $body = 'HELLO-SYMFONY-WORLD';
        $half = (int) ceil(strlen($body) / 2);
        $chunks = [substr($body, 0, $half), substr($body, $half)];

        $first = $this->handleChunk($uploadId, 0, 2, $chunks[0], strlen($body), 'note.txt', 'text/plain');
        self::assertSame(200, $first['status']);
        self::assertTrue($first['json']['success']);
        self::assertFalse($first['json']['data']['completed']);

        $second = $this->handleChunk($uploadId, 1, 2, $chunks[1], strlen($body), 'note.txt', 'text/plain');
        self::assertSame(200, $second['status']);
        self::assertTrue($second['json']['data']['completed']);

        $metadata = $second['json']['data']['metadata'];
        self::assertSame('note.txt', $metadata['name']);
        self::assertSame(strlen($body), $metadata['size']);
        self::assertNotEmpty($metadata['id']);

        // The assembled file exists on the local disk.
        self::assertInstanceOf(TestKernel::class, self::$kernel);
        $root = self::$kernel->uploadRoot();
        self::assertSame($body, file_get_contents($root.'/note.txt'));

        // List returns it.
        $list = $this->json(self::$kernel->handle(Request::create('/file-upload/files', 'GET')));
        self::assertTrue($list['json']['success']);
        self::assertCount(1, $list['json']['data']['files']);
        self::assertSame('note.txt', $list['json']['data']['files'][0]['name']);

        // Delete (soft) → moved to trash.
        $delete = $this->json(self::$kernel->handle(Request::create('/file-upload/files/'.$metadata['id'], 'DELETE')));
        self::assertSame(200, $delete['status']);
        self::assertTrue($delete['json']['success']);
        self::assertFalse(file_exists($root.'/note.txt'));
    }

    public function test_widget_render_emits_route_urls(): void
    {
        self::bootKernel();

        $widget = self::getContainer()->get(FileWidgetRenderer::class);
        self::assertInstanceOf(FileWidgetRenderer::class, $widget);
        $html = $widget->render();

        // Route names in config/routes.php and the renderer must agree.
        self::assertStringContainsString('/file-upload/chunks', $html);
        self::assertStringContainsString('/file-upload/files/__ID__', $html);
        self::assertStringContainsString('window.FileUploadConfig', $html);
        self::assertStringContainsString('file-uploader.umd.js', $html);
    }

    public function test_invalid_upload_id_is_rejected(): void
    {
        self::bootKernel();

        $result = $this->handleChunk('not-a-valid-id', 0, 1, 'AAAA', 4, 'note.txt', 'text/plain');

        self::assertSame(422, $result['status']);
        self::assertFalse($result['json']['success']);
        self::assertArrayHasKey('uploadId', $result['json']['errors']);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function handleChunk(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $bytes,
        int $fileSize,
        string $fileName,
        string $mimeType,
    ): array {
        $tmp = tempnam(sys_get_temp_dir(), 'fuchunk');
        file_put_contents($tmp, $bytes);
        $upload = new UploadedFile($tmp, $fileName, 'application/octet-stream', null, true);

        $request = Request::create(
            '/file-upload/chunks',
            'POST',
            [
                'uploadId' => $uploadId,
                'chunkIndex' => (string) $chunkIndex,
                'totalChunks' => (string) $totalChunks,
                'fileSize' => (string) $fileSize,
                'fileName' => $fileName,
                'mimeType' => $mimeType,
                'fileLastModified' => '1700000000000',
            ],
            [],
            ['fileChunk' => $upload],
        );

        return $this->json(self::$kernel->handle($request));
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
