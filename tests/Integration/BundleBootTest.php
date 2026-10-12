<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Controller\ChatController;
use Stejac\GuardedChatBundle\Guard\AlwaysOnRuntimeSwitch;
use Stejac\GuardedChatBundle\Guard\BudgetExhaustionDetectorInterface;
use Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface;
use Stejac\GuardedChatBundle\Guard\ClientIdentifierResolverInterface;
use Stejac\GuardedChatBundle\Guard\HumanVerifierInterface;
use Stejac\GuardedChatBundle\Guard\NullHumanVerifier;
use Stejac\GuardedChatBundle\Guard\RateLimitBudgetExhaustionDetector;
use Stejac\GuardedChatBundle\Guard\RequestClientIdentifierResolver;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class BundleBootTest extends KernelTestCase
{
    public function testBundleBootsWithNeutralDefaultExtensionPoints(): void
    {
        $this->boot();

        self::assertInstanceOf(ChatController::class, $this->service(ChatController::class));
        self::assertInstanceOf(AlwaysOnRuntimeSwitch::class, $this->service(ChatRuntimeSwitchInterface::class));
        self::assertInstanceOf(NullHumanVerifier::class, $this->service(HumanVerifierInterface::class));
        self::assertInstanceOf(RequestClientIdentifierResolver::class, $this->service(ClientIdentifierResolverInterface::class));
        self::assertInstanceOf(RateLimitBudgetExhaustionDetector::class, $this->service(BudgetExhaustionDetectorInterface::class));
    }

    public function testRateLimitersComeFromBundleConfiguration(): void
    {
        $this->boot(['rate_limits' => ['message' => ['limit' => 3, 'interval' => '2 minutes']]]);

        foreach (['guarded_chat_session', 'guarded_chat_message', 'guarded_chat_message_daily'] as $name) {
            self::assertInstanceOf(RateLimiterFactory::class, $this->container()->get('limiter.'.$name));
        }
    }
}
