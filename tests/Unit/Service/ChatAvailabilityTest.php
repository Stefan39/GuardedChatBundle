<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface;
use Stejac\GuardedChatBundle\Service\ChatAvailability;

final class ChatAvailabilityTest extends TestCase
{
    public function testEnabledOnlyWhenBothStagesAreOn(): void
    {
        self::assertTrue($this->availability(true, true)->isEnabled());
        self::assertFalse($this->availability(true, false)->isEnabled());
        self::assertFalse($this->availability(false, true)->isEnabled());
    }

    public function testMissingPlatformSwitchesTheChatOffByConfiguration(): void
    {
        $availability = $this->availability(true, true, false);

        self::assertFalse($availability->isEnabledByConfiguration());
        self::assertFalse($availability->isEnabled());
    }

    public function testAvailablePlatformKeepsTheResults(): void
    {
        $availability = $this->availability(true, true, true);

        self::assertTrue($availability->isEnabledByConfiguration());
        self::assertTrue($availability->isEnabled());
        self::assertFalse($this->availability(false, true, true)->isEnabledByConfiguration());
    }

    public function testStagesCanBeReadSeparately(): void
    {
        $availability = $this->availability(true, false);

        self::assertTrue($availability->isEnabledByConfiguration());
        self::assertFalse($availability->isEnabledAtRuntime());
    }

    public function testRuntimeSwitchIsAskedOncePerInstance(): void
    {
        $switch = new class implements ChatRuntimeSwitchInterface {
            public int $calls = 0;

            public function isEnabled(): bool
            {
                ++$this->calls;

                return true;
            }
        };
        $availability = new ChatAvailability($switch, true, true);

        $availability->isEnabled();
        $availability->isEnabled();
        $availability->isEnabledAtRuntime();

        self::assertSame(1, $switch->calls);
    }

    private function availability(bool $configured, bool $runtime, bool $platformAvailable = true): ChatAvailability
    {
        return new ChatAvailability(new class($runtime) implements ChatRuntimeSwitchInterface {
            public function __construct(private readonly bool $on)
            {
            }

            public function isEnabled(): bool
            {
                return $this->on;
            }
        }, $configured, $platformAvailable);
    }
}
