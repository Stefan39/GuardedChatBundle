<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Command;

use Stejac\GuardedChatBundle\Service\ChatSessionPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'guarded-chat:prune', description: 'Delete chat sessions and messages past the retention window')]
final class PruneChatSessionsCommand extends Command
{
    public function __construct(
        private readonly ChatSessionPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success(sprintf('Deleted %d chat session(s) past the retention window.', $this->pruner->prune()));

        return Command::SUCCESS;
    }
}
