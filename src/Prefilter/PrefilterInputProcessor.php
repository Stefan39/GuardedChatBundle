<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Prefilter;

use Stejac\GuardedChatBundle\Exception\BlockedInputException;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Symfony AI input processor that stops a request before the model is called: when the visitor's
 * latest message trips the prefilter it throws {@see BlockedInputException}, which means zero requests
 * reach the gateway. It works in any Symfony AI agent, with or without the rest of this bundle.
 *
 * The *latest* user message is checked on purpose: {@see \Symfony\AI\Platform\Message\MessageBag::getUserMessage()}
 * returns the first one, which would let a later injection attempt through.
 */
final readonly class PrefilterInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private OffTopicPrefilter $prefilter,
    ) {
    }

    public function processInput(Input $input): void
    {
        $messages = $input->getMessageBag()->getMessages();
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if (!$message instanceof UserMessage) {
                continue;
            }

            if ($this->prefilter->isBlocked($message->asText() ?? '')) {
                throw new BlockedInputException('The message was blocked by the prefilter.');
            }

            return;
        }
    }
}
