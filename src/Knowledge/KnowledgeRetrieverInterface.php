<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Knowledge;

/**
 * Picks the knowledge that goes into a single answer. The pipeline depends on this interface only, so
 * the search can run on PostgreSQL full text, an embedding store or anything else.
 */
interface KnowledgeRetrieverInterface
{
    /** @return list<KnowledgeSnippetInterface> the best matches for the question, strongest first, at most $limit */
    public function retrieve(string $question, int $limit): array;
}
