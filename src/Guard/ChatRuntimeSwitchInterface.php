<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

/**
 * Second stage of the kill switch: lets an operator turn the chat off without a deploy (a site
 * setting, a feature flag, ...). The first stage is the hard `guarded_chat.enabled` configuration.
 */
interface ChatRuntimeSwitchInterface
{
    public function isEnabled(): bool;
}
