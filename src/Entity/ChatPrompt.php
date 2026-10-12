<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stejac\GuardedChatBundle\Repository\ChatPromptRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A versioned system prompt for the chat assistant. Kept in the database instead of a
 * code constant so a wording fix no longer needs a deploy — exactly one row is active at a time.
 */
#[ORM\Entity(repositoryClass: ChatPromptRepository::class)]
#[ORM\Table(name: 'chat_prompt')]
#[ORM\Index(name: 'idx_chat_prompt_active', columns: ['active'])]
// Partial unique index: enforces "at most one active prompt" at the database level, so two
// concurrent activations (e.g. a doubled admin click) collide on this constraint instead of
// silently leaving two active rows. The 'where' option is written with parentheses because
// PostgreSQL echoes the predicate back that way (`pg_get_expr()`); without the exact match,
// migrations:diff sees a spurious change and wants to drop and recreate an identical index.
#[ORM\UniqueConstraint(name: 'uniq_chat_prompt_active', columns: ['active'], options: ['where' => '(active = true)'])]
class ChatPrompt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'content', type: Types::TEXT)]
    private string $content;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'active', options: ['default' => false])]
    private bool $active = false;

    public function __construct(string $content, \DateTimeImmutable $createdAt)
    {
        $this->content = $content;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
