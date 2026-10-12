<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Knowledge;

/** Default retriever: no knowledge base. Lets the bundle run on any database platform. */
final class NullRetriever implements KnowledgeRetrieverInterface
{
    public function retrieve(string $question, int $limit): array
    {
        return [];
    }
}
