<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Knowledge;

use Stejac\GuardedChatBundle\Repository\ChatKnowledgeSnippetRepository;

/**
 * Full-text search over the stored snippets with PostgreSQL's `to_tsvector`/`ts_rank`, weighted by each
 * snippet's priority. Needs PostgreSQL; on other platforms keep the {@see NullRetriever} or bring your own.
 */
final readonly class PostgresFullTextRetriever implements KnowledgeRetrieverInterface
{
    /** @param non-empty-string $textSearchConfig */
    public function __construct(
        private ChatKnowledgeSnippetRepository $snippets,
        private string $textSearchConfig,
    ) {
    }

    public function retrieve(string $question, int $limit): array
    {
        return $this->snippets->searchFullText($question, $limit, $this->textSearchConfig);
    }
}
