<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

use Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface;

/**
 * Single source of truth for whether the public chat is live. Two stages: the `guarded_chat.enabled`
 * configuration is the hard off-switch that survives a broken database, the runtime switch is the
 * kill switch an operator can flip without a deploy.
 */
final class ChatAvailability
{
    // Memoized per instance: a template function may ask on every render, and the runtime switch
    // can sit behind a database or cache lookup.
    private ?bool $enabledAtRuntime = null;

    public function __construct(
        private readonly ChatRuntimeSwitchInterface $runtimeSwitch,
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->isEnabledAtRuntime();
    }

    public function isEnabledByConfiguration(): bool
    {
        return $this->enabled;
    }

    public function isEnabledAtRuntime(): bool
    {
        return $this->enabledAtRuntime ??= $this->runtimeSwitch->isEnabled();
    }
}
