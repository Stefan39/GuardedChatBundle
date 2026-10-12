<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One persisted turn of a conversation. Kept separate from ChatSession::history,
 * which is capped at the last few turns because it doubles as the model's context window —
 * this table is the complete record used for reviewing and improving the assistant.
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_message')]
#[ORM\Index(name: 'idx_chat_message_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_chat_message_session', columns: ['session_id'])]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: ChatSession::class)]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private ChatSession $session;

    #[ORM\Column(name: 'role', length: 20, enumType: ChatRole::class)]
    private ChatRole $role;

    #[ORM\Column(name: 'content', type: Types::TEXT)]
    private string $content;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * Ids of the knowledge snippets that fed this answer. Null on user turns and on
     * answers produced without a retrieval hit, so "no knowledge involved" stays distinguishable
     * from "knowledge was involved but empty".
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'context_snippet_ids', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $contextSnippetIds;

    /** @param list<string>|null $contextSnippetIds */
    public function __construct(
        ChatSession $session,
        ChatRole $role,
        string $content,
        \DateTimeImmutable $createdAt,
        ?array $contextSnippetIds = null,
    ) {
        $this->session = $session;
        $this->role = $role;
        $this->content = $content;
        $this->createdAt = $createdAt;
        $this->contextSnippetIds = $contextSnippetIds;
    }

    /** @return list<string>|null */
    public function getContextSnippetIds(): ?array
    {
        return $this->contextSnippetIds;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSession(): ChatSession
    {
        return $this->session;
    }

    public function getRole(): ChatRole
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
