<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface;

final class OffSwitch implements ChatRuntimeSwitchInterface
{
    public function isEnabled(): bool
    {
        return false;
    }
}
