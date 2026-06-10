<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Xakki\FileUploader\Protocol\ResponseFactory;

/**
 * Renders the shared Upload Protocol v1 envelope as a JsonResponse, matching the
 * Laravel binding's encoding flags so the wire bytes are identical across bindings.
 */
trait JsonEnvelope
{
    /**
     * @param  array<string, scalar>  $params
     */
    private function success(mixed $data, string $message, int $status = 200, ?string $code = null, array $params = []): JsonResponse
    {
        return $this->envelope(ResponseFactory::success($data, $message, $code, $params), $status);
    }

    /**
     * @param  array<string, string[]>  $errors
     * @param  array<string, scalar>  $params
     */
    private function error(string $message, int $status = 404, array $errors = [], ?string $code = null, array $params = []): JsonResponse
    {
        return $this->envelope(ResponseFactory::error($message, $errors, $code, $params), $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelope(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $response;
    }
}
