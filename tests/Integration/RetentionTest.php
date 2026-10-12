<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Message\PruneChatSessionsMessage;
use Stejac\GuardedChatBundle\MessageHandler\PruneChatSessionsMessageHandler;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Scheduler\ChatRetentionSchedule;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RetentionTest extends KernelTestCase
{
    public function testTheMessageHandlerDeletesSessionsPastTheDefaultNinetyDays(): void
    {
        $this->boot();
        $this->seed();

        $this->service(PruneChatSessionsMessageHandler::class)(new PruneChatSessionsMessage());

        $this->assertOnlyTheFreshSessionRemains();
    }

    public function testTheConsoleCommandPrunesAndReportsTheCount(): void
    {
        $kernel = $this->boot();
        $this->seed();

        $command = (new Application($kernel))->find('guarded-chat:prune');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 chat session(s)', $tester->getDisplay());
        $this->assertOnlyTheFreshSessionRemains();
    }

    public function testTheRetentionPeriodIsConfigurable(): void
    {
        $this->boot(['retention' => ['days' => 3650]]);
        $this->seed();

        $this->service(PruneChatSessionsMessageHandler::class)(new PruneChatSessionsMessage());

        $this->entityManager()->clear();
        self::assertCount(2, $this->service(ChatSessionRepository::class)->findAll());
    }

    public function testNoScheduleIsRegisteredByDefault(): void
    {
        $this->boot();

        self::assertFalse($this->container()->has(ChatRetentionSchedule::class));
    }

    public function testTheScheduleCanBeSwitchedOn(): void
    {
        $this->boot(['retention' => ['schedule' => ['enabled' => true, 'name' => 'chat', 'cron' => '0 4 * * *', 'timezone' => 'Europe/Berlin', 'state_cache' => 'cache.app']]]);

        $schedule = $this->service(ChatRetentionSchedule::class)->getSchedule();

        self::assertCount(1, $schedule->getRecurringMessages());
        self::assertNotNull($schedule->getState());
    }

    private function seed(): void
    {
        $sessions = $this->service(ChatSessionRepository::class);
        $messages = $this->service(ChatMessageRepository::class);

        $old = new ChatSession(Uuid::v4(), new \DateTimeImmutable('-200 days'));
        $sessions->save($old);
        $messages->append($old, ChatRole::User, 'old', new \DateTimeImmutable('-200 days'));

        $fresh = new ChatSession(Uuid::v4(), new \DateTimeImmutable('-1 day'));
        $sessions->save($fresh);
        $messages->append($fresh, ChatRole::User, 'fresh', new \DateTimeImmutable('-1 day'));
    }

    private function assertOnlyTheFreshSessionRemains(): void
    {
        $this->entityManager()->clear();
        self::assertCount(1, $this->service(ChatSessionRepository::class)->findAll());
        $messages = $this->service(ChatMessageRepository::class)->findAll();
        self::assertCount(1, $messages);
        self::assertSame('fresh', $messages[0]->getContent());
    }
}
