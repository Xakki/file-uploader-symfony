<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Service;

use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Xakki\FileUploader\Contracts\Storage;
use Xakki\FileUploader\FileManager;

/**
 * Age-based garbage collection for a STAGING upload area, complementing the
 * core's trash-only {@see FileManager::cleanupTrash()}.
 *
 * Two failure modes that core cleanup never reaches:
 *  - Abandoned ACTIVE files: uploaded, never published, never explicitly deleted
 *    (user closes the tab). They have no `deletedAt`, so trash cleanup ignores
 *    them and they live forever.
 *  - Incomplete `.chunks/<uploadId>/` directories from aborted mid-uploads: the
 *    final file is never assembled, so the temp dir is never torn down.
 *
 * Both are OPT-IN / conservatively defaulted so a general-purpose uploader never
 * silently expires live files (see the bundle config). This class reuses the core
 * public API for active files and the bundle's own Flysystem operator for chunk
 * directory ages (the Storage seam exposes no last-modified time).
 */
final class StagingGarbageCollector
{
    /**
     * @param  string  $temporaryDirectory  Raw config value for the chunk dir (e.g. ".chunks").
     */
    public function __construct(
        private readonly FileManager $manager,
        private readonly Storage $storage,
        private readonly FilesystemOperator $operator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $temporaryDirectory,
    ) {}

    /**
     * Purge ACTIVE (non-trashed) files whose createdAt is older than $ttlDays.
     * A value <= 0 disables the pass. Deletes the blob and its metadata directly
     * (this is a maintenance run, so it bypasses the user-facing, ownership-gated
     * FileManager::delete()).
     */
    public function collectAbandonedActive(int $ttlDays): int
    {
        if ($ttlDays <= 0) {
            return 0;
        }

        $threshold = $this->clock->now()->modify("-{$ttlDays} days");
        $removed = 0;

        foreach ($this->manager->list() as $file) {
            $createdAt = $file['createdAt'] ?? null;
            if (! is_string($createdAt) || $createdAt === '') {
                continue;
            }

            try {
                $created = new DateTimeImmutable($createdAt);
            } catch (Throwable $e) {
                $this->logger->warning((string) $e);

                continue;
            }

            if ($created > $threshold) {
                continue;
            }

            $hash = (string) ($file['id'] ?? '');
            if ($hash === '') {
                continue;
            }

            try {
                $metadata = $this->manager->readMetadata($hash);
            } catch (Throwable $e) {
                $this->logger->warning((string) $e);

                continue;
            }

            // Defensive: list() only returns active entries, but never purge a
            // file that has since been trashed (trash cleanup owns that path).
            if ($metadata->deletedAt !== null) {
                continue;
            }

            $deleted = false;
            if ($metadata->path && $this->storage->exists($metadata->path)) {
                $deleted = $this->storage->delete($metadata->path);
            }

            $metadataPath = $this->manager->metadataPath($hash);
            if ($this->storage->exists($metadataPath)) {
                $deleted = $this->storage->delete($metadataPath) || $deleted;
            }

            if ($deleted) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Remove incomplete `.chunks/<uploadId>/` directories whose newest chunk file
     * is older than $ttlDays. Keyed on the NEWEST chunk mtime per directory so a
     * slow, still-in-progress multi-chunk upload is never killed mid-flight.
     * A value <= 0 disables the pass.
     */
    public function collectStaleChunks(int $ttlDays): int
    {
        if ($ttlDays <= 0) {
            return 0;
        }

        $base = trim($this->temporaryDirectory, '/');
        if ($base === '' || ! $this->operator->directoryExists($base)) {
            return 0;
        }

        $nowTs = $this->clock->now()->getTimestamp();
        $thresholdTs = $nowTs - ($ttlDays * 86400);

        // Newest chunk-file mtime per upload directory.
        $newest = [];
        try {
            foreach ($this->operator->listContents($base, true) as $attr) {
                /** @var StorageAttributes $attr */
                if (! $attr->isFile()) {
                    continue;
                }

                $relative = substr($attr->path(), strlen($base) + 1);
                $segment = explode('/', $relative, 2)[0];
                if ($segment === '') {
                    continue;
                }

                $dir = $base.'/'.$segment;
                $mtime = $attr->lastModified() ?? $nowTs;
                if (! isset($newest[$dir]) || $mtime > $newest[$dir]) {
                    $newest[$dir] = $mtime;
                }
            }

            // Truly-empty upload dirs (no chunk files): use the directory's own mtime
            // when available; skip when unknown (empty = zero disk, never urgent).
            foreach ($this->operator->listContents($base, false) as $attr) {
                /** @var StorageAttributes $attr */
                if (! $attr->isDir()) {
                    continue;
                }
                if (isset($newest[$attr->path()])) {
                    continue;
                }
                $mtime = $attr->lastModified();
                if ($mtime !== null) {
                    $newest[$attr->path()] = $mtime;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning((string) $e);
        }

        $removed = 0;
        foreach ($newest as $dir => $mtime) {
            if ($mtime <= $thresholdTs) {
                if ($this->storage->deleteDirectory($dir)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
