<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Message\PruneChatSessionsMessage;
use Stejac\GuardedChatBundle\Scheduler\ChatRetentionSchedule;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class ChatRetentionScheduleTest extends TestCase
{
    public function testSchedulesTheDeletionMessage(): void
    {
        $schedule = (new ChatRetentionSchedule('30 3 * * *', 'Europe/Berlin'))->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(1, $messages);
        $now = new \DateTimeImmutable();
        $context = new MessageContext('default', $messages[0]->getId(), $messages[0]->getTrigger(), $now);
        self::assertInstanceOf(PruneChatSessionsMessage::class, [...$messages[0]->getMessages($context)][0]);
        self::assertNull($schedule->getState());
    }

    public function testKeepsItsCheckpointWhenAStateCacheIsGiven(): void
    {
        $schedule = (new ChatRetentionSchedule('30 3 * * *', 'UTC', new ArrayAdapter()))->getSchedule();

        self::assertNotNull($schedule->getState());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }
}
