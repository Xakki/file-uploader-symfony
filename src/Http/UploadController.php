<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use Xakki\FileUploader\Dto\FileMetadata;
use Xakki\FileUploader\Exception\AttentionException;
use Xakki\FileUploader\FileManager;
use Xakki\FileUploader\Protocol\ChunkValidator;
use Xakki\FileUploader\Protocol\MessageCatalog;

/**
 * POST {prefix}/chunks — receives one chunk, returns the Upload Protocol v1 envelope.
 */
final class UploadController
{
    use JsonEnvelope;
    use ResolvesLocale;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FileManager $manager,
        private readonly LoggerInterface $logger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $fields */
        $fields = $request->request->all();
        $locales = is_array($this->config['locales'] ?? null) ? $this->config['locales'] : [];
        $locale = $this->resolveLocale($this->config, $request->request->get('locale'));

        $errors = ChunkValidator::validate($fields, $locales, $locale);
        if ($request->files->get('fileChunk') === null) {
            $errors['fileChunk'][] = MessageCatalog::resolve('validation.field_required', ['field' => 'fileChunk'], $locale);
        }
        if ($errors !== []) {
            // No core code for the aggregate; surface the first per-field message (already
            // localized) and let the `errors` map carry the rest — matches the Laravel binding.
            return $this->error($this->firstError($errors), 422, $errors);
        }

        $payload = new SymfonyChunkPayload($request);

        try {
            $result = $this->manager->handleChunk($payload);
        } catch (AttentionException $e) {
            return $this->error(
                MessageCatalog::resolve($e->code(), $e->params(), $locale),
                422,
                code: $e->code(),
                params: $e->params(),
            );
        } catch (Throwable $e) {
            // Coded, client-safe failures are handled above (AttentionException). Anything
            // else is unexpected — log the detail, return a generic message (no leak).
            $this->logger->error((string) $e);

            return $this->error('Upload failed due to a server error.', 500);
        }

        $completed = $result instanceof FileMetadata;
        $data = ['completed' => $completed];

        if ($completed) {
            $data['metadata'] = $this->manager->formatFileForResponse($result);
            $code = 'message.upload_completed';
            $params = ['name' => $payload->fileName()];
        } else {
            $code = 'message.chunk_received';
            $params = ['current' => $payload->chunkIndex() + 1, 'total' => $payload->totalChunks()];
        }

        return $this->success(
            $data,
            MessageCatalog::resolve($code, $params, $locale),
            code: $code,
            params: $params,
        );
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function firstError(array $errors): string
    {
        foreach ($errors as $messages) {
            if ($messages !== []) {
                return (string) $messages[0];
            }
        }

        return 'The upload request is invalid.';
    }
}
