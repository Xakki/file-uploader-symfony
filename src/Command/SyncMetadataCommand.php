<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Xakki\FileUploader\FileManager;

#[AsCommand(name: 'file-uploader:sync-metadata', description: 'Synchronize metadata files with the stored uploads.')]
final class SyncMetadataCommand extends Command
{
    public function __construct(private readonly FileManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->manager->syncMetadata();
        $output->writeln(sprintf(
            'Metadata synchronized. Created: %d, Updated: %d, Deleted: %d.',
            $result['created'],
            $result['updated'],
            $result['deleted'],
        ));

        return Command::SUCCESS;
    }
}
