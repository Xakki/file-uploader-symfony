<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the shared Upload Protocol v1 fixtures against the Symfony server through a booted
 * kernel. The fixtures ship inside the core package (`xakki/file-uploader`); the JS client
 * suite runs the same fixtures — the cross-language anti-drift gate
 * (protocol/fixtures/README.md §"Harness contract").
 */
final class ConformanceTest extends KernelTestCase
{
    private const FIXTURES_DIR = __DIR__.'/../vendor/xakki/file-uploader/protocol/fixtures';

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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(self::FIXTURES_DIR.'/*.json') ?: [] as $path) {
            /** @var array<string, mixed> $fixture */
            $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            yield $fixture['name'] => [$fixture];
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    #[DataProvider('fixtures')]
    public function test_fixture(array $fixture): void
    {
        self::bootKernel();
        $fillByte = $fixture['file']['fillByte'];

        foreach ($fixture['requests'] as $request) {
            $response = $this->postChunk($request['fields'], $request['chunkBytes'], $fillByte);
            $expect = $request['expect'];

            self::assertSame($expect['status'], $response->getStatusCode(), $fixture['name'].': status');

            $envelope = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expect['success'], $envelope['success'], $fixture['name'].': success');

            if (isset($expect['code'])) {
                self::assertSame($expect['code'], $envelope['code'] ?? null, $fixture['name'].': code');
            }

            if (isset($expect['data'])) {
                self::assertArrayHasKey('data', $envelope);
                self::assertSubset($expect['data'], $envelope['data'], $fixture['name'].': data.');
            }

            if (isset($expect['errors'])) {
                self::assertArrayHasKey('errors', $envelope, $fixture['name'].': errors present');
                foreach ($expect['errors'] as $field) {
                    self::assertArrayHasKey($field, $envelope['errors'], $fixture['name'].": errors[$field]");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function postChunk(array $fields, int $chunkBytes, int $fillByte): Response
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'fuchunk');
        file_put_contents($tmp, str_repeat(chr($fillByte), $chunkBytes));
        $upload = new UploadedFile($tmp, (string) ($fields['fileName'] ?? 'chunk'), 'application/octet-stream', null, true);

        $request = Request::create('/file-upload/chunks', 'POST', array_map('strval', $fields), [], ['fileChunk' => $upload]);

        return self::$kernel->handle($request);
    }

    /**
     * Deep partial-subset assertion: every key in $expected must exist in $actual with an
     * equal (recursively, for arrays) value. Extra keys in $actual are ignored.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private static function assertSubset(array $expected, array $actual, string $path): void
    {
        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $actual, $path.$key);
            if (is_array($value)) {
                self::assertIsArray($actual[$key], $path.$key);
                self::assertSubset($value, $actual[$key], $path.$key.'.');
            } else {
                self::assertSame($value, $actual[$key], $path.$key);
            }
        }
    }
}
