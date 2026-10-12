<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Dto\ChatTranscriptFilter;
use Stejac\GuardedChatBundle\Entity\ChatMessage;
use Stejac\GuardedChatBundle\Entity\ChatPrompt;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Repository\ChatPromptRepository;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RepositoryTest extends KernelTestCase
{
    private const string DAY = '2026-07-26T10:00:00+00:00';

    public function testDeletingSessionsPastTheCutoffTakesTheirMessagesAlong(): void
    {
        $this->boot();
        $sessions = $this->service(ChatSessionRepository::class);
        $messages = $this->service(ChatMessageRepository::class);
        $old = $this->session('2026-01-01T10:00:00+00:00');
        $recent = $this->session('2026-07-01T10:00:00+00:00');
        $messages->append($old, ChatRole::User, 'old question', new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $messages->append($recent, ChatRole::User, 'recent question', new \DateTimeImmutable('2026-07-01T10:00:00+00:00'));

        $deleted = $sessions->deleteCreatedBefore(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        self::assertSame(1, $deleted);
        $this->entityManager()->clear();
        self::assertCount(1, $sessions->findAll());
        $remaining = $messages->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('recent question', $remaining[0]->getContent());
    }

    public function testSessionStatistics(): void
    {
        $this->boot();
        $sessions = $this->service(ChatSessionRepository::class);
        $old = $this->session('2026-01-01T10:00:00+00:00');
        $old->appendTurn(ChatRole::User, 'a', new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $recent = $this->session('2026-07-01T10:00:00+00:00');
        $recent->appendTurn(ChatRole::User, 'a', new \DateTimeImmutable('2026-07-01T10:00:00+00:00'));
        $recent->appendTurn(ChatRole::User, 'b', new \DateTimeImmutable('2026-07-01T10:01:00+00:00'));
        $sessions->save($recent);

        $since = new \DateTimeImmutable('2026-06-01T00:00:00+00:00');
        self::assertSame(1, $sessions->countCreatedSince($since));
        self::assertSame(2, $sessions->sumMessagesSince($since));
        self::assertSame(0, $sessions->sumMessagesSince(new \DateTimeImmutable('2027-01-01T00:00:00+00:00')));
    }

    public function testRecentSessionsComeNewestFirst(): void
    {
        $this->boot();
        $this->session('2026-07-01T10:00:00+00:00');
        $newest = $this->session('2026-07-03T10:00:00+00:00');
        $this->session('2026-07-02T10:00:00+00:00');

        $recent = $this->service(ChatSessionRepository::class)->findRecent(2);

        self::assertCount(2, $recent);
        self::assertTrue($newest->getId()->equals($recent[0]->getId()));
    }

    public function testSessionTranscriptHonoursDateRangeAndPagination(): void
    {
        $this->boot();
        $sessions = $this->service(ChatSessionRepository::class);
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $day) {
            $this->session($day.'T12:00:00+00:00');
        }

        // `to` is inclusive of the whole target day, so the 3rd (12:00) still counts.
        $filter = new ChatTranscriptFilter(new \DateTimeImmutable('2026-07-02T00:00:00+00:00'), new \DateTimeImmutable('2026-07-03T00:00:00+00:00'));
        self::assertSame(2, $sessions->countForTranscript($filter));
        $days = array_map(static fn (ChatSession $s): string => $s->getCreatedAt()->format('Y-m-d'), $sessions->findForTranscript($filter));
        self::assertSame(['2026-07-03', '2026-07-02'], $days);

        $page = $sessions->findForTranscript(new ChatTranscriptFilter(limit: 2, offset: 2));
        self::assertSame(['2026-07-02', '2026-07-01'], array_map(static fn (ChatSession $s): string => $s->getCreatedAt()->format('Y-m-d'), $page));
        self::assertSame(4, $sessions->countForTranscript(new ChatTranscriptFilter(limit: 2)));
    }

    public function testMessageTranscriptIsOldestFirstAndFiltered(): void
    {
        $this->boot();
        $messages = $this->service(ChatMessageRepository::class);
        $session = $this->session(self::DAY);
        $messages->append($session, ChatRole::User, 'first', new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $messages->append($session, ChatRole::Assistant, 'second', new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $messages->append($session, ChatRole::User, 'third', new \DateTimeImmutable('2026-07-27T10:00:00+00:00'));
        $messages->append($session, ChatRole::User, 'outside', new \DateTimeImmutable('2026-08-30T10:00:00+00:00'));

        $filter = new ChatTranscriptFilter(new \DateTimeImmutable('2026-07-26T00:00:00+00:00'), new \DateTimeImmutable('2026-07-27T00:00:00+00:00'));

        self::assertSame(3, $messages->countForTranscript($filter));
        // 'first' and 'second' share a timestamp: the id tie-break keeps them in insertion order.
        self::assertSame(['first', 'second', 'third'], array_map(static fn (ChatMessage $m): string => $m->getContent(), $messages->findForTranscript($filter)));
    }

    public function testFindForSessionAndFirstQuestions(): void
    {
        $this->boot();
        $messages = $this->service(ChatMessageRepository::class);
        $one = $this->session(self::DAY);
        $two = $this->session(self::DAY);
        $silent = $this->session(self::DAY);
        $messages->append($one, ChatRole::User, 'one: first', new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $messages->append($one, ChatRole::Assistant, 'one: answer', new \DateTimeImmutable('2026-07-26T10:00:01+00:00'));
        $messages->append($one, ChatRole::User, 'one: second', new \DateTimeImmutable('2026-07-26T10:00:02+00:00'));
        $messages->append($two, ChatRole::User, 'two: first', new \DateTimeImmutable('2026-07-26T10:00:03+00:00'), ['snippet-1']);

        self::assertSame(['one: first', 'one: answer', 'one: second'], array_map(static fn (ChatMessage $m): string => $m->getContent(), $messages->findForSession($one)));
        self::assertSame(
            [(string) $one->getId() => 'one: first', (string) $two->getId() => 'two: first'],
            $messages->findFirstUserMessagesFor([$one, $two, $silent]),
        );
        self::assertSame([], $messages->findFirstUserMessagesFor([]));
        self::assertSame(['snippet-1'], $messages->findForSession($two)[0]->getContextSnippetIds());
    }

    public function testWithoutAStoredPromptThereIsNoActiveVersion(): void
    {
        $this->boot();

        self::assertNull($this->service(ChatPromptRepository::class)->findActive());
    }

    public function testOnlyOnePromptVersionIsActive(): void
    {
        $this->boot();
        $prompts = $this->service(ChatPromptRepository::class);

        $v1 = $prompts->saveNewVersion('version one', new \DateTimeImmutable('2026-07-01T10:00:00+00:00'));
        $v2 = $prompts->saveNewVersion('version two', new \DateTimeImmutable('2026-07-02T10:00:00+00:00'));

        $active = $prompts->findActive();
        self::assertNotNull($active);
        self::assertSame('version two', $active->getContent());
        self::assertFalse($v1->isActive());
        self::assertTrue($v2->isActive());
        self::assertSame(['version two', 'version one'], array_map(static fn (ChatPrompt $p): string => $p->getContent(), $prompts->findAllNewestFirst()));

        $prompts->activate($v1);

        $active = $prompts->findActive();
        self::assertNotNull($active);
        self::assertSame('version one', $active->getContent());
        self::assertFalse($v2->isActive());
    }

    public function testThePartialUniqueIndexRefusesASecondActivePrompt(): void
    {
        $this->boot();
        $em = $this->entityManager();
        $first = new ChatPrompt('a', new \DateTimeImmutable(self::DAY));
        $second = new ChatPrompt('b', new \DateTimeImmutable(self::DAY));
        $first->activate();
        $second->activate();
        $em->persist($first);
        $em->persist($second);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->flush();
    }

    private function session(string $createdAt): ChatSession
    {
        $session = new ChatSession(Uuid::v4(), new \DateTimeImmutable($createdAt));
        $this->service(ChatSessionRepository::class)->save($session);

        return $session;
    }
}
