<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Internal;

use Stejac\GuardedChatBundle\Exception\ChatBudgetExhaustedException;
use Stejac\GuardedChatBundle\Exception\ChatGatewayException;
use Stejac\GuardedChatBundle\Guard\BudgetExhaustionDetectorInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The only class that talks to Symfony AI (0.x, so minor releases may break it). Everything else in
 * the bundle depends on this adapter's plain `string` stream and on the bundle's own exceptions, which
 * keeps a Symfony AI upgrade to this one file.
 *
 * @internal
 */
final readonly class AiStreamAdapter
{
    public function __construct(
        private AgentInterface $agent,
        private BudgetExhaustionDetectorInterface $budgetDetector,
        private int $maxOutputTokens,
    ) {
    }

    /**
     * Streams the model's text deltas. Nothing happens until the first iteration: the agent's input
     * processors (the prefilter) and the HTTP request both run lazily, so a failure surfaces on the
     * first advance of the generator, not on this call.
     *
     * @return \Generator<int, string>
     *
     * @throws ChatBudgetExhaustedException when the gateway reports an exhausted budget
     * @throws ChatGatewayException         on any other transport, HTTP or stream failure
     */
    public function stream(MessageBag $messages): \Generator
    {
        try {
            $execution = $this->agent->call($messages, ['stream' => true, 'max_tokens' => $this->maxOutputTokens]);

            foreach ($execution->asStream() as $delta) {
                if ($delta instanceof TextDelta && '' !== $delta->getText()) {
                    yield $delta->getText();
                }
            }
        } catch (PlatformException|AgentException|TransportExceptionInterface $e) {
            // Transport errors are not translated by the platform and arrive as raw HTTP client
            // exceptions; a stream that ends without a finish reason arrives as IncompleteStreamException
            // and counts as a failure mid-stream, not as a short reply.
            throw $this->budgetDetector->isBudgetExhausted($e) ? new ChatBudgetExhaustedException('The model gateway budget is exhausted.', 0, $e) : new ChatGatewayException('The model gateway failed: '.$e->getMessage(), 0, $e);
        }
    }
}
