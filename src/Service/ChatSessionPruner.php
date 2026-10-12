<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;

/**
 * Deletes chat sessions older than the retention window. Their messages go with them through the
 * chat_message foreign key's ON DELETE CASCADE.
 */
final readonly class ChatSessionPruner
{
    public function __construct(
        private ChatSessionRepository $sessions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $retentionDays,
    ) {
    }

    /** @return int number of deleted sessions */
    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify('-'.$this->retentionDays.' days');
        $deleted = $this->sessions->deleteCreatedBefore($cutoff);

        $this->logger->info('Pruned chat sessions past retention', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->format(\DATE_ATOM),
        ]);

        return $deleted;
    }
}
