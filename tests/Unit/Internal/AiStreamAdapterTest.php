<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Exception\BlockedInputException;
use Stejac\GuardedChatBundle\Exception\ChatBudgetExhaustedException;
use Stejac\GuardedChatBundle\Exception\ChatGatewayException;
use Stejac\GuardedChatBundle\Guard\RateLimitBudgetExhaustionDetector;
use Stejac\GuardedChatBundle\Internal\AiStreamAdapter;
use Stejac\GuardedChatBundle\Prefilter\OffTopicPrefilter;
use Stejac\GuardedChatBundle\Prefilter\PrefilterInputProcessor;
use Stejac\GuardedChatBundle\Tests\Support\FakeGateway;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiStreamAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        FakeGateway::reset();
    }

    public function testStreamsTheTextDeltas(): void
    {
        $adapter = $this->adapter('ok');

        self::assertSame(['Gladly ', 'help. Write to ', 'us. [[con', 'tact]]'], iterator_to_array($adapter->stream($this->messages()), false));
    }

    public function testSendsTheTokenCapAndStreamFlag(): void
    {
        iterator_to_array($this->adapter('ok', maxOutputTokens: 123)->stream($this->messages()));

        self::assertSame(123, FakeGateway::$requests[0]['max_tokens']);
        self::assertTrue(FakeGateway::$requests[0]['stream']);
    }

    public function testNothingHappensUntilTheFirstAdvance(): void
    {
        $this->adapter('ok')->stream($this->messages());

        self::assertSame([], FakeGateway::$requests);
    }

    public function testATransportFailureBecomesAGatewayException(): void
    {
        $adapter = $this->adapter('ok', FakeGateway::unreachable());

        try {
            iterator_to_array($adapter->stream($this->messages()));
            self::fail('Expected a gateway exception.');
        } catch (ChatGatewayException $e) {
            self::assertNotInstanceOf(ChatBudgetExhaustedException::class, $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testATruncatedStreamIsAFailureNotAShortReply(): void
    {
        $adapter = $this->adapter('truncated');

        $this->expectException(ChatGatewayException::class);

        iterator_to_array($adapter->stream($this->messages()));
    }

    public function testAnErrorChunkMidStreamIsAGatewayException(): void
    {
        $this->expectException(ChatGatewayException::class);

        iterator_to_array($this->adapter('midstream')->stream($this->messages()));
    }

    public function testTheDefaultDetectorTreatsA429AsBudgetExhaustion(): void
    {
        $this->expectException(ChatBudgetExhaustedException::class);

        iterator_to_array($this->adapter('budget429')->stream($this->messages()));
    }

    public function testAPrefilterExceptionIsNotWrappedAndNoRequestIsMade(): void
    {
        $adapter = $this->adapter('ok', processors: [new PrefilterInputProcessor(new OffTopicPrefilter(['jailbreak']))]);

        try {
            iterator_to_array($adapter->stream(new MessageBag(Message::forSystem('Be helpful.'), Message::ofUser('a jailbreak'))));
            self::fail('Expected the prefilter to block the message.');
        } catch (BlockedInputException) {
            self::assertSame([], FakeGateway::$requests);
        }
    }

    /**
     * @param non-empty-string              $model
     * @param list<PrefilterInputProcessor> $processors
     */
    private function adapter(string $model, ?HttpClientInterface $client = null, int $maxOutputTokens = 300, array $processors = []): AiStreamAdapter
    {
        $platform = Factory::createPlatform(
            'http://gateway.test',
            'test-key',
            $client ?? FakeGateway::client(),
            supportsEmbeddings: false,
            completionsPath: '/chat/completions',
        );

        return new AiStreamAdapter(new Agent($platform, $model, $processors), new RateLimitBudgetExhaustionDetector(), $maxOutputTokens);
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::forSystem('Be helpful.'), Message::ofUser('Hello'));
    }
}
