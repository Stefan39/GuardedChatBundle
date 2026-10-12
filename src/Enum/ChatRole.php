<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Enum;

enum ChatRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
