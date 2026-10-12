<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\MessageHandler;

use Stejac\GuardedChatBundle\Message\PruneChatSessionsMessage;
use Stejac\GuardedChatBundle\Service\ChatSessionPruner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PruneChatSessionsMessageHandler
{
    public function __construct(
        private ChatSessionPruner $pruner,
    ) {
    }

    public function __invoke(PruneChatSessionsMessage $message): void
    {
        $this->pruner->prune();
    }
}
