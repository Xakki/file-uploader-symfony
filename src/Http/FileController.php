<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Xakki\FileUploader\Exception\AuthorizationException;
use Xakki\FileUploader\FileManager;
use Xakki\FileUploader\Protocol\MessageCatalog;

/**
 * Management endpoints: list / delete / restore / cleanup. Thin pass-through to
 * the core FileManager; gating flags come from bundle config.
 */
final class FileController
{
    use JsonEnvelope;
    use ResolvesLocale;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FileManager $manager,
        private readonly RequestStack $requests,
    ) {}

    public function index(): JsonResponse
    {
        $files = ($this->config['allow_list'] ?? true) ? $this->manager->list() : [];

        return $this->success(['files' => $files], 'ok');
    }

    public function destroy(string $id): JsonResponse
    {
        $locale = $this->locale();

        if (! ($this->config['allow_delete'] ?? true)) {
            return $this->error(MessageCatalog::resolve('message.not_allow', [], $locale), 403, code: 'message.not_allow');
        }

        try {
            $deleted = $this->manager->delete($id);
        } catch (AuthorizationException $e) {
            return $this->error(
                MessageCatalog::resolve($e->code(), $e->params(), $locale),
                403,
                code: $e->code(),
                params: $e->params(),
            );
        }

        if (! $deleted) {
            return $this->error(MessageCatalog::resolve('message.not_found', [], $locale), 404, code: 'message.not_found');
        }

        return $this->success(['id' => $id], MessageCatalog::resolve('message.moved_to_trash', [], $locale), code: 'message.moved_to_trash');
    }

    public function restore(string $id): JsonResponse
    {
        $locale = $this->locale();

        if (! ($this->config['allow_delete'] ?? true)) {
            return $this->error(MessageCatalog::resolve('message.not_allow', [], $locale), 403, code: 'message.not_allow');
        }

        try {
            $restored = $this->manager->restore($id);
        } catch (AuthorizationException $e) {
            return $this->error(
                MessageCatalog::resolve($e->code(), $e->params(), $locale),
                403,
                code: $e->code(),
                params: $e->params(),
            );
        }

        if (! $restored) {
            return $this->error(MessageCatalog::resolve('message.not_found', [], $locale), 404, code: 'message.not_found');
        }

        return $this->success(['id' => $id], MessageCatalog::resolve('message.restored', [], $locale), code: 'message.restored');
    }

    public function cleanup(): JsonResponse
    {
        $locale = $this->locale();

        if (! ($this->config['allow_cleanup'] ?? true)) {
            return $this->error(MessageCatalog::resolve('message.not_allow', [], $locale), 403, code: 'message.not_allow');
        }

        $count = $this->manager->cleanupTrash();

        return $this->success(
            ['count' => $count],
            MessageCatalog::resolve('message.cleanup_done', ['count' => $count], $locale),
            code: 'message.cleanup_done',
            params: ['count' => $count],
        );
    }

    private function locale(): string
    {
        return $this->resolveLocale($this->config, $this->requests->getCurrentRequest()?->request->get('locale'));
    }
}
