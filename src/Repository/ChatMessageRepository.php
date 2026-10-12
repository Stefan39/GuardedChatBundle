<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Stejac\GuardedChatBundle\Dto\ChatTranscriptFilter;
use Stejac\GuardedChatBundle\Entity\ChatMessage;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Symfony\Bridge\Doctrine\Types\UuidType;

/** @extends ServiceEntityRepository<ChatMessage> */
class ChatMessageRepository extends ServiceEntityRepository
{
    use ChatTranscriptQueryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /** @param list<string>|null $contextSnippetIds */
    public function append(
        ChatSession $session,
        ChatRole $role,
        string $content,
        \DateTimeImmutable $now,
        ?array $contextSnippetIds = null,
    ): void {
        $em = $this->getEntityManager();
        $em->persist(new ChatMessage($session, $role, $content, $now, $contextSnippetIds));
        $em->flush();
    }

    /**
     * How often each knowledge snippet has been used in an answer, keyed by snippet id. Counted in PHP
     * over the answers that carry snippet ids, which keeps it portable; retention bounds the row count.
     *
     * @return array<string, int>
     */
    public function countSnippetUsage(): array
    {
        $messages = $this->createQueryBuilder('m')
            ->where('m.contextSnippetIds IS NOT NULL')
            ->getQuery()
            ->toIterable();

        $usage = [];
        foreach ($messages as $message) {
            foreach ($message->getContextSnippetIds() ?? [] as $id) {
                $usage[$id] = ($usage[$id] ?? 0) + 1;
            }
        }

        return $usage;
    }

    /** @return list<ChatMessage> */
    public function findForSession(ChatSession $session): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.session = :session')
            ->setParameter('session', $session->getId(), UuidType::NAME)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Resolves the earliest user question for each of the given sessions in a single query,
     * instead of one query per session.
     *
     * @param list<ChatSession> $sessions
     *
     * @return array<string, string> Map of session ID (string) to the content of its first user message.
     *                               Sessions without a user message are simply absent from the map.
     */
    public function findFirstUserMessagesFor(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }

        // One bound parameter per session instead of IN (:list): a list of uuids cannot be bound with
        // the uuid type, and the type decides whether a platform compares strings or binary values.
        $qb = $this->createQueryBuilder('m');
        $anySession = $qb->expr()->orX();
        foreach ($sessions as $i => $session) {
            $anySession->add("m.session = :session$i");
            $qb->setParameter("session$i", $session->getId(), UuidType::NAME);
        }

        $messages = $qb
            ->where($anySession)
            ->andWhere('m.role = :role')
            ->setParameter('role', ChatRole::User)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $firstQuestions = [];
        foreach ($messages as $message) {
            $sessionId = (string) $message->getSession()->getId();
            if (!isset($firstQuestions[$sessionId])) {
                $firstQuestions[$sessionId] = $message->getContent();
            }
        }

        return $firstQuestions;
    }

    /**
     * Paginated message listing, ordered oldest-first so a conversation reads in the direction it
     * happened. Eager-loads the owning session via the join so reading the session id per row does
     * not trigger a query per row.
     *
     * @return list<ChatMessage>
     */
    public function findForTranscript(ChatTranscriptFilter $filter): array
    {
        $qb = $this->createQueryBuilder('m');
        $this->applyTranscriptFilter($qb, $filter, 'm');

        $this->paginateTranscript($qb, $filter, 'm', 'ASC');

        return $qb->select('m', 's')
            ->join('m.session', 's')
            ->getQuery()
            ->getResult();
    }

    public function countForTranscript(ChatTranscriptFilter $filter): int
    {
        $qb = $this->createQueryBuilder('m');

        return (int) $this->applyTranscriptFilter($qb, $filter, 'm')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
