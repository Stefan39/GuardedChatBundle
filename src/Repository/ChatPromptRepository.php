<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Stejac\GuardedChatBundle\Entity\ChatPrompt;

/** @extends ServiceEntityRepository<ChatPrompt> */
class ChatPromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatPrompt::class);
    }

    public function findActive(): ?ChatPrompt
    {
        // Secondary sort by id: the partial unique index already guarantees at most one active row,
        // but this keeps the pick deterministic even if that invariant were ever violated. Ids are
        // UUIDv7 and therefore chronologically sortable, so "most recently created wins" on a tie.
        return $this->findOneBy(['active' => true], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<ChatPrompt> */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }

    public function saveNewVersion(string $content, \DateTimeImmutable $now): ChatPrompt
    {
        $prompt = new ChatPrompt($content, $now);
        $this->getEntityManager()->persist($prompt);
        $this->activate($prompt);

        return $prompt;
    }

    public function activate(ChatPrompt $prompt): void
    {
        $em = $this->getEntityManager();

        // Two separate flushes on purpose: the database enforces "at most one active row" via a
        // partial unique index that is checked per statement, not deferred to the end of the
        // transaction. Deactivating and activating in a single flush would race against that index.
        // Committing the deactivation first guarantees zero active rows before the new one is on.
        foreach ($this->findBy(['active' => true]) as $active) {
            $active->deactivate();
        }
        $em->flush();

        $prompt->activate();
        $em->flush();
    }
}
