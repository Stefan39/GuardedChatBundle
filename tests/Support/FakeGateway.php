<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stand-in for an OpenAI-compatible gateway such as LiteLLM. The *model name* picks the scenario, so a
 * test selects behaviour through the `guarded_chat.model` configuration. Every request body is recorded.
 */
final class FakeGateway
{
    public const string BUDGET_MESSAGE = 'Budget has been exceeded! Current cost: 1.01, Max budget: 1.0';

    /** @var list<array<mixed>> */
    public static array $requests = [];

    /** @var list<string> */
    public static array $chunks = ['Gladly ', 'help. Write to ', 'us. [[con', 'tact]]'];

    public static function reset(): void
    {
        self::$requests = [];
        self::$chunks = ['Gladly ', 'help. Write to ', 'us. [[con', 'tact]]'];
    }

    /** @return list<array{role: string, content: string}> the chat messages of the n-th recorded request */
    public static function messages(int $request): array
    {
        $messages = self::$requests[$request]['messages'] ?? null;
        if (!\is_array($messages)) {
            throw new \LogicException(sprintf('Request %d carried no messages.', $request));
        }

        $result = [];
        foreach ($messages as $message) {
            if (!\is_array($message) || !\is_string($message['role'] ?? null) || !\is_string($message['content'] ?? null)) {
                throw new \LogicException(sprintf('Request %d carried a malformed message.', $request));
            }
            $result[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        return $result;
    }

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(self::respond(...));
    }

    /** A gateway nobody is listening on: every request fails at the transport level. */
    public static function unreachable(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new TransportException('Connection refused');
        });
    }

    /** @param array<string, mixed> $options */
    public static function respond(string $method, string $url, array $options): ResponseInterface
    {
        $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
        if (!\is_array($body)) {
            throw new \LogicException('The gateway received a request body that is not JSON.');
        }
        self::$requests[] = $body;
        $model = \is_string($body['model'] ?? null) ? $body['model'] : 'ok';

        return match ($model) {
            'budget400' => self::error(400, self::BUDGET_MESSAGE, 'budget_exceeded'),
            'budget429' => self::error(429, self::BUDGET_MESSAGE, 'budget_exceeded'),
            'ratelimit429' => self::error(429, 'Rate limit reached for key', 'rate_limit_error'),
            'server500' => self::error(500, 'upstream exploded', 'server_error'),
            default => self::stream($model),
        };
    }

    private static function error(int $status, string $message, string $type): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => (string) $status]], \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function stream(string $model): MockResponse
    {
        $event = static fn (array $data): string => 'data: '.json_encode($data, \JSON_THROW_ON_ERROR)."\n\n";
        $delta = static fn (string $text): array => ['id' => 'x', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]]];

        $chunks = array_map(static fn (string $text): string => $event($delta($text)), self::$chunks);

        if ('midstream' === $model) {
            $chunks[] = $event(['error' => ['message' => 'gateway died', 'type' => 'server_error', 'code' => 'server_error']]);
        } elseif ('truncated' !== $model) {
            $chunks[] = $event(['id' => 'x', 'model' => $model, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]);
            $chunks[] = $event(['id' => 'x', 'model' => $model, 'choices' => [], 'usage' => ['prompt_tokens' => 412, 'completion_tokens' => 23, 'total_tokens' => 435]]);
            $chunks[] = "data: [DONE]\n\n";
        }

        return new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }
}
