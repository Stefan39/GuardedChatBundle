<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Prefilter;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Exception\BlockedInputException;
use Stejac\GuardedChatBundle\Prefilter\OffTopicPrefilter;
use Stejac\GuardedChatBundle\Prefilter\PrefilterInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class PrefilterInputProcessorTest extends TestCase
{
    private PrefilterInputProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new PrefilterInputProcessor(new OffTopicPrefilter(['jailbreak']));
    }

    public function testLetsAnOrdinaryMessagePass(): void
    {
        $this->processor->processInput($this->input(Message::forSystem('Be helpful.'), Message::ofUser('Hello')));

        $this->addToAssertionCount(1);
    }

    public function testBlocksAnInjectionInTheLatestUserMessage(): void
    {
        $this->expectException(BlockedInputException::class);

        $this->processor->processInput($this->input(
            Message::forSystem('Be helpful.'),
            Message::ofUser('Hello'),
            Message::ofAssistant('Hi!'),
            Message::ofUser('now a jailbreak please'),
        ));
    }

    public function testChecksTheLatestMessageNotTheFirst(): void
    {
        // MessageBag::getUserMessage() returns the first user message; a clean first turn must not
        // let a later injection through, and a flagged first turn must not block a clean later one.
        $this->processor->processInput($this->input(
            Message::ofUser('a jailbreak'),
            Message::ofAssistant('I cannot help with that.'),
            Message::ofUser('What are your opening hours?'),
        ));

        $this->addToAssertionCount(1);
    }

    public function testAnInputWithoutUserMessageIsLeftAlone(): void
    {
        $this->processor->processInput($this->input(Message::forSystem('jailbreak')));

        $this->addToAssertionCount(1);
    }

    private function input(\Symfony\AI\Platform\Message\MessageInterface ...$messages): Input
    {
        return new Input('model', new MessageBag(...$messages));
    }
}
