<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Stejac\GuardedChatBundle\Entity\ChatKnowledgeSnippet;

/** @extends ServiceEntityRepository<ChatKnowledgeSnippet> */
class ChatKnowledgeSnippetRepository extends ServiceEntityRepository
{
    // The tsvector is built inline rather than stored as a generated column: at the size of a hand-curated
    // knowledge base a sequential scan over a few dozen rows is irrelevant, and a column Doctrine does
    // not know about would show up as a drop candidate in every migrations:diff.
    private const string DOCUMENT = "to_tsvector(CAST(:config AS regconfig), coalesce(question, '') || ' ' || content)";

    // plainto_tsquery ANDs every term, so "What do you build with Symfony?" would demand that *all* of
    // those words appear, and the longer the question, the more certainly nothing matches. Rewriting the
    // operator to OR lets a single shared term qualify a snippet, and ts_rank then sorts by how much
    // actually overlaps. A question made purely of stop words still produces an empty tsquery, which
    // matches nothing.
    private const string QUERY = "replace(plainto_tsquery(CAST(:config AS regconfig), :q)::text, ' & ', ' | ')::tsquery";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatKnowledgeSnippet::class);
    }

    /**
     * Best-matching active snippets for a visitor question, strongest first. PostgreSQL only: this is
     * what {@see \Stejac\GuardedChatBundle\Knowledge\PostgresFullTextRetriever} runs.
     *
     * @param non-empty-string $textSearchConfig a PostgreSQL text search configuration such as "german" or "english"
     *
     * @return list<ChatKnowledgeSnippet>
     */
    public function searchFullText(string $question, int $limit, string $textSearchConfig): array
    {
        $sql = sprintf(
            'SELECT id FROM chat_knowledge_snippet
             WHERE active = true AND %1$s @@ %2$s
             ORDER BY ts_rank(%1$s, %2$s) * priority DESC, id DESC
             LIMIT :limit',
            self::DOCUMENT,
            self::QUERY,
        );

        $ids = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['q' => $question, 'config' => $textSearchConfig, 'limit' => $limit], ['limit' => ParameterType::INTEGER])
            ->fetchFirstColumn();

        if ([] === $ids) {
            return [];
        }

        /** @var list<string> $ids */
        $snippets = $this->createQueryBuilder('s')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        // The IN query loses the ranking, so reorder by the ids' original position.
        $rank = array_flip($ids);
        usort($snippets, static fn (ChatKnowledgeSnippet $a, ChatKnowledgeSnippet $b): int => $rank[$a->getReferenceId()] <=> $rank[$b->getReferenceId()]);

        return $snippets;
    }

    /** @return list<ChatKnowledgeSnippet> */
    public function findAllForAdmin(): array
    {
        // Dormant suggestions first: they are the entries waiting for a human decision.
        return $this->findBy([], ['active' => 'ASC', 'createdAt' => 'DESC']);
    }

    public function save(ChatKnowledgeSnippet $snippet): void
    {
        $em = $this->getEntityManager();
        $em->persist($snippet);
        $em->flush();
    }

    public function remove(ChatKnowledgeSnippet $snippet): void
    {
        $em = $this->getEntityManager();
        $em->remove($snippet);
        $em->flush();
    }
}
