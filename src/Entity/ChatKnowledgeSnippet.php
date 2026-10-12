<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stejac\GuardedChatBundle\Enum\ChatKnowledgeSource;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeSnippetInterface;
use Stejac\GuardedChatBundle\Repository\ChatKnowledgeSnippetRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One hand-curated piece of knowledge the assistant may draw on. FAQ entries and
 * general knowledge share this table; the only difference is whether `question` is filled.
 */
#[ORM\Entity(repositoryClass: ChatKnowledgeSnippetRepository::class)]
#[ORM\Table(name: 'chat_knowledge_snippet')]
#[ORM\Index(name: 'idx_chat_knowledge_active', columns: ['active'])]
class ChatKnowledgeSnippet implements KnowledgeSnippetInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'title', length: 200)]
    private string $title;

    #[ORM\Column(name: 'question', type: Types::TEXT, nullable: true)]
    private ?string $question;

    #[ORM\Column(name: 'content', type: Types::TEXT)]
    private string $content;

    #[ORM\Column(name: 'priority', type: Types::SMALLINT, options: ['default' => 1])]
    private int $priority;

    #[ORM\Column(name: 'source_url', length: 500, nullable: true)]
    private ?string $sourceUrl;

    #[ORM\Column(name: 'active', options: ['default' => true])]
    private bool $active;

    #[ORM\Column(name: 'source', length: 20, enumType: ChatKnowledgeSource::class)]
    private ChatKnowledgeSource $source;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $title,
        ?string $question,
        string $content,
        int $priority,
        ?string $sourceUrl,
        ChatKnowledgeSource $source,
        \DateTimeImmutable $now,
    ) {
        $this->title = $title;
        $this->question = $question;
        $this->content = $content;
        $this->priority = $priority;
        $this->sourceUrl = $sourceUrl;
        $this->source = $source;
        // API-created snippets stay dormant until a human releases them in the admin area:
        // Suggestions may be derived from visitor transcripts, so an unreviewed write path
        // would let a visitor steer the assistant's own knowledge.
        $this->active = ChatKnowledgeSource::Admin === $source;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getReferenceId(): string
    {
        return (string) $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSource(): ChatKnowledgeSource
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        string $title,
        ?string $question,
        string $content,
        int $priority,
        ?string $sourceUrl,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->question = $question;
        $this->content = $content;
        $this->priority = $priority;
        $this->sourceUrl = $sourceUrl;
        $this->updatedAt = $now;
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
