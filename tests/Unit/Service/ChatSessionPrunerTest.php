<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Service\ChatSessionPruner;
use Symfony\Component\Clock\MockClock;

final class ChatSessionPrunerTest extends TestCase
{
    public function testLogsTheRunOnTheConfiguredLevel(): void
    {
        $logger = $this->logger();

        $deleted = $this->pruner($logger, LogLevel::WARNING)->prune();

        self::assertSame(3, $deleted);
        self::assertSame([[LogLevel::WARNING, 'Pruned chat sessions past retention', ['deleted' => 3, 'cutoff' => '2026-07-24T12:00:00+00:00']]], $logger->records);
    }

    public function testLogsOnInfoByDefault(): void
    {
        $logger = $this->logger();

        $this->pruner($logger)->prune();

        self::assertSame(LogLevel::INFO, $logger->records[0][0]);
    }

    /** @param LogLevel::*|null $logLevel */
    private function pruner(AbstractLogger $logger, ?string $logLevel = null): ChatSessionPruner
    {
        $sessions = $this->createStub(ChatSessionRepository::class);
        $sessions->method('deleteCreatedBefore')->willReturn(3);
        $clock = new MockClock('2026-10-22 12:00:00', 'UTC');

        return null === $logLevel
            ? new ChatSessionPruner($sessions, $clock, $logger, 90)
            : new ChatSessionPruner($sessions, $clock, $logger, 90, $logLevel);
    }

    /** @return AbstractLogger&object{records: list<array{mixed, string|\Stringable, array<mixed>}>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
    }
}
