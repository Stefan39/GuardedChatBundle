<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Scheduler;

use Stejac\GuardedChatBundle\Message\PruneChatSessionsMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Optional recurring prune, registered when `guarded_chat.retention.schedule.enabled` is true. With a
 * state cache the checkpoint survives deploys and a missed run is caught up once, not once per
 * missed tick, which matters when a privacy policy promises deletion after a fixed number of days.
 */
final readonly class ChatRetentionSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private string $cron,
        private string $timezone,
        private ?CacheInterface $state = null,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        if (null !== $this->state) {
            $schedule->stateful($this->state)->processOnlyLastMissedRun(true);
        }

        return $schedule->add(RecurringMessage::cron($this->cron, new PruneChatSessionsMessage(), new \DateTimeZone($this->timezone)));
    }
}
