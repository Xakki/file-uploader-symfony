<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Xakki\FileUploader\FileManager;

#[AsCommand(name: 'file-uploader:cleanup', description: 'Remove expired files from the file uploader trash bin.')]
final class CleanupTrashCommand extends Command
{
    public function __construct(private readonly FileManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->manager->cleanupTrash();
        $output->writeln(sprintf('Removed %d expired file(s) from trash.', $count));

        return Command::SUCCESS;
    }
}
