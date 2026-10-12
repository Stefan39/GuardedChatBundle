<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

/** Proves that a session start comes from a human (a CAPTCHA, proof of work, ...). */
interface HumanVerifierInterface
{
    /** @param string $token the opaque `verificationToken` the widget sent with the session request */
    public function verify(string $token): bool;
}
