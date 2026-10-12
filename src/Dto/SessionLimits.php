<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Dto;

/** Lifetime and size limits of one anonymous chat session (the `guarded_chat.session` configuration). */
final readonly class SessionLimits
{
    public function __construct(
        public int $lifetimeSeconds = 7200,
        public int $idleTimeoutSeconds = 1800,
        public int $maxMessages = 20,
        public int $maxHistoryEntries = 6,
    ) {
    }
}
