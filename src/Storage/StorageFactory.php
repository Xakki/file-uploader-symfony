<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Storage;

use League\Flysystem\FilesystemOperator;
use Xakki\FileUploader\Storage\FlysystemStorage;

/**
 * Builds the core FlysystemStorage from a Flysystem operator, turning the
 * configured public_url_base into the optional URL-resolver closure that the
 * core expects (Flysystem adapters do not uniformly expose public URLs).
 */
final class StorageFactory
{
    public static function create(FilesystemOperator $operator, ?string $publicUrlBase): FlysystemStorage
    {
        $resolver = $publicUrlBase === null
            ? null
            : static fn (string $path): string => rtrim($publicUrlBase, '/').'/'.ltrim($path, '/');

        return new FlysystemStorage($operator, $resolver);
    }
}
