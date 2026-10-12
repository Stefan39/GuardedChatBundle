<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

use Psr\Clock\ClockInterface;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Security\ChatSessionTokenSigner;
use Symfony\Component\Uid\Uuid;

final readonly class ChatSessionStarter
{
    public function __construct(
        private ChatSessionRepository $sessions,
        private ChatSessionTokenSigner $signer,
        private ClockInterface $clock,
        private SessionLimits $limits,
    ) {
    }

    /** Creates a session and returns its signed token. */
    public function start(): string
    {
        $session = new ChatSession(Uuid::v4(), $this->clock->now(), $this->limits);
        $this->sessions->save($session);

        return $this->signer->sign($session->getId(), $this->limits->lifetimeSeconds);
    }
}
