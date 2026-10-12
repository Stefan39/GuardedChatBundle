<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Symfony\Component\Uid\Uuid;

final class ChatSessionTest extends TestCase
{
    private const string ID = '018f1e0a-0000-7000-8000-000000000001';

    public function testFreshSessionHasNoHistoryAndIsNotExpired(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $session = new ChatSession(Uuid::fromString(self::ID), $now);

        self::assertSame([], $session->getHistory());
        self::assertSame(0, $session->getMessageCount());
        self::assertNull($session->getLastMessageAt());
        self::assertSame($now, $session->getCreatedAt());
        self::assertFalse($session->hasReachedMessageLimit());
        self::assertFalse($session->isExpired($now));
    }

    public function testAppendTurnAddsToHistoryAndCountsOnlyVisitorMessages(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $session = new ChatSession(Uuid::fromString(self::ID), $now);

        $session->appendTurn(ChatRole::User, 'Hello', $now);
        $session->appendTurn(ChatRole::Assistant, 'Hi!', $now);

        self::assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi!'],
        ], $session->getHistory());
        self::assertSame(1, $session->getMessageCount());
        self::assertSame($now, $session->getLastMessageAt());
    }

    public function testHistoryIsTrimmedToTheConfiguredWindow(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $limits = new SessionLimits(maxHistoryEntries: 4);
        $session = new ChatSession(Uuid::fromString(self::ID), $now, $limits);

        for ($i = 1; $i <= 5; ++$i) {
            $session->appendTurn(ChatRole::User, 'question '.$i, $now, $limits);
            $session->appendTurn(ChatRole::Assistant, 'answer '.$i, $now, $limits);
        }

        self::assertCount(4, $session->getHistory());
        self::assertSame(['role' => 'user', 'content' => 'question 4'], $session->getHistory()[0]);
        self::assertSame(['role' => 'assistant', 'content' => 'answer 5'], $session->getHistory()[3]);
    }

    public function testMessageLimitFollowsTheConfiguration(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $limits = new SessionLimits(maxMessages: 3);
        $session = new ChatSession(Uuid::fromString(self::ID), $now, $limits);

        for ($i = 0; $i < 3; ++$i) {
            self::assertFalse($session->hasReachedMessageLimit($limits));
            $session->appendTurn(ChatRole::User, 'question '.$i, $now, $limits);
        }

        self::assertTrue($session->hasReachedMessageLimit($limits));
    }

    public function testExpiresAfterTheConfiguredLifetime(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $limits = new SessionLimits(lifetimeSeconds: 600);
        $session = new ChatSession(Uuid::fromString(self::ID), $now, $limits);

        self::assertSame($now->modify('+600 seconds')->getTimestamp(), $session->getExpiresAt()->getTimestamp());
        self::assertFalse($session->isExpired($now->modify('+9 minutes'), $limits));
        self::assertTrue($session->isExpired($now->modify('+11 minutes'), $limits));
    }

    public function testExpiresAfterTheConfiguredIdleTimeoutSinceTheLastMessage(): void
    {
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $limits = new SessionLimits(idleTimeoutSeconds: 300);
        $session = new ChatSession(Uuid::fromString(self::ID), $now, $limits);
        $lastMessageAt = $now->modify('+10 minutes');
        $session->appendTurn(ChatRole::User, 'question', $lastMessageAt, $limits);

        self::assertFalse($session->isExpired($lastMessageAt->modify('+4 minutes'), $limits));
        self::assertTrue($session->isExpired($lastMessageAt->modify('+6 minutes'), $limits));
    }
}
