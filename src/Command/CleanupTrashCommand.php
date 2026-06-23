<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Xakki\FileUploader\FileManager;
use Xakki\SymfonyFileUploader\Service\StagingGarbageCollector;

#[AsCommand(name: 'file-uploader:cleanup', description: 'Remove expired files from the file uploader trash bin (and, when configured, abandoned active files + stale chunk directories).')]
final class CleanupTrashCommand extends Command
{
    public function __construct(
        private readonly FileManager $manager,
        private readonly StagingGarbageCollector $gc,
        private readonly ?int $activeTtlDays,
        private readonly int $chunkTtlDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->manager->cleanupTrash();
        $output->writeln(sprintf('Removed %d expired file(s) from trash.', $count));

        if ($this->activeTtlDays !== null && $this->activeTtlDays > 0) {
            $abandoned = $this->gc->collectAbandonedActive($this->activeTtlDays);
            $output->writeln(sprintf('Removed %d abandoned active file(s).', $abandoned));
        }

        if ($this->chunkTtlDays > 0) {
            $chunks = $this->gc->collectStaleChunks($this->chunkTtlDays);
            $output->writeln(sprintf('Removed %d stale chunk directory(ies).', $chunks));
        }

        return Command::SUCCESS;
    }
}
