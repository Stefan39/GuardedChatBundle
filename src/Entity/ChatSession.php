<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChatSessionRepository::class)]
#[ORM\Table(name: 'chat_session')]
class ChatSession
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'last_message_at', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(name: 'message_count')]
    private int $messageCount = 0;

    /** @var list<array{role: string, content: string}> */
    #[ORM\Column(name: 'history', type: Types::JSON, options: ['default' => '[]'])]
    private array $history = [];

    public function __construct(Uuid $id, \DateTimeImmutable $now, SessionLimits $limits = new SessionLimits())
    {
        $this->id = $id;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify('+'.$limits->lifetimeSeconds.' seconds');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function appendTurn(ChatRole $role, string $content, \DateTimeImmutable $now, SessionLimits $limits = new SessionLimits()): void
    {
        $this->history[] = ['role' => $role->value, 'content' => $content];
        if (\count($this->history) > $limits->maxHistoryEntries) {
            $this->history = \array_slice($this->history, -$limits->maxHistoryEntries);
        }
        if (ChatRole::User === $role) {
            ++$this->messageCount;
        }
        $this->lastMessageAt = $now;
    }

    public function hasReachedMessageLimit(SessionLimits $limits = new SessionLimits()): bool
    {
        return $this->messageCount >= $limits->maxMessages;
    }

    public function isExpired(\DateTimeImmutable $now, SessionLimits $limits = new SessionLimits()): bool
    {
        if ($now > $this->expiresAt) {
            return true;
        }

        // Idle-timeout only applies once a first message exists; otherwise a session with
        // no traffic yet would falsely expire before the hard lifetime cap.
        if (null === $this->lastMessageAt) {
            return false;
        }

        return $now->getTimestamp() > $this->lastMessageAt->getTimestamp() + $limits->idleTimeoutSeconds;
    }
}
