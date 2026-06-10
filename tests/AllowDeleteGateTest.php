<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Xakki\FileUploader\FileManager;
use Xakki\SymfonyFileUploader\Http\FileController;

/**
 * The `allow_delete` flag must be enforced server-side (not only hidden in the widget UI):
 * with it disabled, destroy/restore return 403 before the manager is ever touched.
 */
final class AllowDeleteGateTest extends TestCase
{
    public function test_destroy_is_forbidden_when_allow_delete_disabled(): void
    {
        $response = $this->controller()->destroy('any-id');

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $json */
        $json = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['success']);
        self::assertSame('message.not_allow', $json['code']);
    }

    public function test_restore_is_forbidden_when_allow_delete_disabled(): void
    {
        $response = $this->controller()->restore('any-id');

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $json */
        $json = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['success']);
        self::assertSame('message.not_allow', $json['code']);
    }

    private function controller(): FileController
    {
        $manager = $this->createMock(FileManager::class);
        $manager->expects(self::never())->method('delete');
        $manager->expects(self::never())->method('restore');

        return new FileController(
            ['allow_delete' => false, 'locales' => ['en'], 'locale' => 'en'],
            $manager,
            new RequestStack,
        );
    }
}
