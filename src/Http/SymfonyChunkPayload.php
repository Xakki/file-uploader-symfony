<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use Xakki\FileUploader\Contracts\ChunkPayload;
use Xakki\FileUploader\Protocol\ChunkValidator;

/**
 * One chunk adapted from a Symfony Request: POST fields + the `fileChunk` upload.
 * Field-shape validation is done up front by the controller via the core
 * {@see ChunkValidator}, so the accessors here just cast.
 */
final class SymfonyChunkPayload implements ChunkPayload
{
    public function __construct(private readonly Request $request) {}

    public function uploadId(): string
    {
        return (string) $this->field('uploadId');
    }

    public function chunkIndex(): int
    {
        return (int) $this->field('chunkIndex');
    }

    public function totalChunks(): int
    {
        return (int) $this->field('totalChunks');
    }

    public function fileName(): string
    {
        return (string) $this->field('fileName');
    }

    public function fileSize(): int
    {
        return (int) $this->field('fileSize');
    }

    public function mimeType(): string
    {
        return (string) $this->field('mimeType');
    }

    public function fileLastModified(): int
    {
        return (int) $this->field('fileLastModified');
    }

    public function fileHash(): ?string
    {
        $hash = $this->field('fileHash');

        return $hash !== null && $hash !== '' ? $hash : null;
    }

    public function locale(): ?string
    {
        $locale = $this->field('locale');

        return $locale !== null && $locale !== '' ? $locale : null;
    }

    public function detectedMimeType(): ?string
    {
        // Only a fallback for an empty client mimeType; never let detection failure
        // (e.g. symfony/mime absent) abort the upload.
        try {
            return $this->file()?->getMimeType();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return resource
     */
    public function chunkStream()
    {
        $file = $this->file();
        $stream = $file ? fopen($file->getPathname(), 'rb') : false;
        if ($stream === false) {
            throw new RuntimeException('Unable to open uploaded chunk stream.');
        }

        return $stream;
    }

    private function field(string $key): ?string
    {
        $value = $this->request->request->get($key);

        return $value === null ? null : (string) $value;
    }

    private function file(): ?UploadedFile
    {
        $file = $this->request->files->get('fileChunk');

        return $file instanceof UploadedFile ? $file : null;
    }
}
