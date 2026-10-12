<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Entity\ChatKnowledgeSnippet;
use Stejac\GuardedChatBundle\Enum\ChatKnowledgeSource;

final class ChatKnowledgeSnippetTest extends TestCase
{
    private const string NOW = '2026-07-29 08:00:00';

    public function testSnippetCreatedByAnAdminIsActiveImmediately(): void
    {
        self::assertTrue($this->snippet(ChatKnowledgeSource::Admin)->isActive());
    }

    public function testSnippetSuggestedThroughTheApiStaysDormantUntilReleased(): void
    {
        $snippet = $this->snippet(ChatKnowledgeSource::Api);

        self::assertFalse($snippet->isActive());

        $snippet->activate();
        self::assertTrue($snippet->isActive());

        $snippet->deactivate();
        self::assertFalse($snippet->isActive());
    }

    public function testUpdateChangesTheContentAndTouchesTheTimestamp(): void
    {
        $snippet = $this->snippet(ChatKnowledgeSource::Admin);
        $later = new \DateTimeImmutable('2026-08-01 12:00:00');

        $snippet->update('New title', 'New question?', 'New content.', 3, 'https://example.com/new', $later);

        self::assertSame('New title', $snippet->getTitle());
        self::assertSame('New question?', $snippet->getQuestion());
        self::assertSame('New content.', $snippet->getContent());
        self::assertSame(3, $snippet->getPriority());
        self::assertSame('https://example.com/new', $snippet->getSourceUrl());
        self::assertSame($later, $snippet->getUpdatedAt());
        self::assertSame(self::NOW, $snippet->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame(ChatKnowledgeSource::Admin, $snippet->getSource());
    }

    public function testAnUnsavedSnippetHasNoId(): void
    {
        $snippet = $this->snippet(ChatKnowledgeSource::Admin);

        self::assertNull($snippet->getId());
        self::assertSame('', $snippet->getReferenceId());
    }

    private function snippet(ChatKnowledgeSource $source): ChatKnowledgeSnippet
    {
        return new ChatKnowledgeSnippet('Title', null, 'Content.', 1, null, $source, new \DateTimeImmutable(self::NOW));
    }
}
