<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class ConfigurationTest extends KernelTestCase
{
    public function testThePromptDefaultIsMandatoryBecauseTheBundleShipsNoPromptText(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"default" under "guarded_chat\.prompt"/');

        $this->boot(['prompt' => []]);
    }

    public function testPlatformAndModelAreMandatory(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(['platform' => '']);
    }

    public function testSessionLimitsHaveSensibleDefaults(): void
    {
        $this->boot();

        $limits = $this->service(SessionLimits::class);

        self::assertSame(7200, $limits->lifetimeSeconds);
        self::assertSame(1800, $limits->idleTimeoutSeconds);
        self::assertSame(20, $limits->maxMessages);
        self::assertSame(6, $limits->maxHistoryEntries);
    }

    public function testSessionLimitsAreConfigurable(): void
    {
        $this->boot(['session' => ['lifetime' => 60, 'idle_timeout' => 30, 'max_messages' => 2, 'max_history' => 4]]);

        $limits = $this->service(SessionLimits::class);

        self::assertSame([60, 30, 2, 4], [$limits->lifetimeSeconds, $limits->idleTimeoutSeconds, $limits->maxMessages, $limits->maxHistoryEntries]);
    }
}
