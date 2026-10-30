<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Internal;

use Stejac\GuardedChatBundle\Exception\ChatPlatformMissingException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Stands in for a platform service that does not exist, so the container still compiles. The chat is
 * switched off in that case; every call here is a programming error and says what to configure.
 *
 * @internal
 */
final class MissingPlatform implements PlatformInterface
{
    public function __construct(private readonly string $platformId)
    {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        throw ChatPlatformMissingException::forPlatform($this->platformId);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        throw ChatPlatformMissingException::forPlatform($this->platformId);
    }
}
