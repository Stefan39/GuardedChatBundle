<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Stejac\GuardedChatBundle\Dto\ChatTranscriptFilter;
use Stejac\GuardedChatBundle\Entity\ChatSession;

/** @extends ServiceEntityRepository<ChatSession> */
class ChatSessionRepository extends ServiceEntityRepository
{
    use ChatTranscriptQueryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatSession::class);
    }

    public function save(ChatSession $session, bool $flush = true): void
    {
        $this->getEntityManager()->persist($session);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum of visitor messages across sessions started since the given moment. Messages are counted
     * per session, not per timestamp, so a session that started before the window contributes nothing.
     */
    public function sumMessagesSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.messageCount), 0)')
            ->where('s.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ChatSession> */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes sessions created before the given cutoff, returning how many were removed. Their
     * messages go with them through the chat_message foreign key's ON DELETE CASCADE.
     */
    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(sprintf('DELETE FROM %s s WHERE s.createdAt < :cutoff', ChatSession::class))
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    /** @return list<ChatSession> */
    public function findForTranscript(ChatTranscriptFilter $filter): array
    {
        $qb = $this->createQueryBuilder('s');
        $this->applyTranscriptFilter($qb, $filter, 's');

        $this->paginateTranscript($qb, $filter, 's', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function countForTranscript(ChatTranscriptFilter $filter): int
    {
        $qb = $this->createQueryBuilder('s');

        return (int) $this->applyTranscriptFilter($qb, $filter, 's')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
