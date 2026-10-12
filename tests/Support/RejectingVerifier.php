<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Stejac\GuardedChatBundle\Guard\HumanVerifierInterface;

final class RejectingVerifier implements HumanVerifierInterface
{
    public function verify(string $token): bool
    {
        return 'human' === $token;
    }
}
