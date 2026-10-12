<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

/** Default runtime switch: the chat is always on, only the `guarded_chat.enabled` configuration can turn it off. */
final class AlwaysOnRuntimeSwitch implements ChatRuntimeSwitchInterface
{
    public function isEnabled(): bool
    {
        return true;
    }
}
