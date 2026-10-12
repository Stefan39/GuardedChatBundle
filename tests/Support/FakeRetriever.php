<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeSnippetInterface;

/** Returns whatever a test put into {@see self::$snippets} and remembers how it was asked. */
final class FakeRetriever implements KnowledgeRetrieverInterface
{
    /** @var list<KnowledgeSnippetInterface> */
    public static array $snippets = [];

    /** @var list<array{question: string, limit: int}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$snippets = [];
        self::$calls = [];
    }

    public function retrieve(string $question, int $limit): array
    {
        self::$calls[] = ['question' => $question, 'limit' => $limit];

        return self::$snippets;
    }
}
