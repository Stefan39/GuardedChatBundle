<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Enum;

enum ChatKnowledgeSource: string
{
    /** Created by a person in an admin area: active immediately. */
    case Admin = 'admin';

    /** Suggested through an API: dormant until a human releases it. */
    case Api = 'api';
}
