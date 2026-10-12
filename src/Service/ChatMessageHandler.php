<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

use Stejac\GuardedChatBundle\Dto\ChatReplyEvent;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Exception\BlockedInputException;
use Stejac\GuardedChatBundle\Exception\ChatMessageLimitReachedException;
use Stejac\GuardedChatBundle\Internal\AiStreamAdapter;
use Stejac\GuardedChatBundle\Knowledge\ChatKnowledgeFormatter;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeSnippetInterface;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The chat pipeline: one visitor message in, a stream of reply events out. */
final readonly class ChatMessageHandler
{
    /** @param array<string, string> $actionMarkers action type => marker text */
    public function __construct(
        private AiStreamAdapter $ai,
        private ChatSessionRepository $sessions,
        private ChatMessageRepository $transcript,
        private ChatPromptProvider $prompts,
        private TranslatorInterface $translator,
        private SessionLimits $limits,
        private KnowledgeRetrieverInterface $knowledge,
        private ChatKnowledgeFormatter $knowledgeFormatter,
        private int $maxSnippets,
        private array $actionMarkers,
    ) {
    }

    /**
     * Runs eagerly up to the model call and returns the reply stream. The stream itself is lazy: the
     * gateway is only reached when it is first advanced.
     *
     * @return \Generator<int, ChatReplyEvent>
     *
     * @throws ChatMessageLimitReachedException when the session is used up
     */
    public function handle(ChatSession $session, string $userMessage, \DateTimeImmutable $now, string $locale): \Generator
    {
        if ($session->hasReachedMessageLimit($this->limits)) {
            throw new ChatMessageLimitReachedException('The session reached its message limit.');
        }

        // The visitor's turn is persisted before streaming starts, so a client abort or a gateway
        // failure mid-stream still counts towards the session's message cap instead of silently
        // vanishing. buildMessages() must run first: it reads the history as it stood *before* this
        // message, so the appendTurn() below does not make the new message appear twice in the context.
        $snippets = $this->knowledge->retrieve($userMessage, $this->maxSnippets);
        $messages = $this->buildMessages($session, $userMessage, $locale, $snippets);
        $session->appendTurn(ChatRole::User, $userMessage, $now, $this->limits);
        $this->sessions->save($session);
        $this->transcript->append($session, ChatRole::User, $userMessage, $now);

        return $this->streamAndPersist($session, $messages, $now, $locale, $snippets);
    }

    /** @param list<KnowledgeSnippetInterface> $snippets */
    private function buildMessages(ChatSession $session, string $userMessage, string $locale, array $snippets): MessageBag
    {
        $messages = new MessageBag(Message::forSystem($this->prompts->current($locale).$this->knowledgeFormatter->toPromptBlock($snippets, $locale)));
        foreach ($session->getHistory() as $turn) {
            $messages->add(ChatRole::Assistant->value === $turn['role'] ? Message::ofAssistant($turn['content']) : Message::ofUser($turn['content']));
        }
        $messages->add(Message::ofUser($userMessage));

        return $messages;
    }

    /**
     * @param list<KnowledgeSnippetInterface> $snippets
     *
     * @return \Generator<int, ChatReplyEvent>
     */
    private function streamAndPersist(ChatSession $session, MessageBag $messages, \DateTimeImmutable $now, string $locale, array $snippets): \Generator
    {
        $parser = new ActionMarkerParser($this->actionMarkers);
        $assistantText = '';

        try {
            foreach ($this->ai->stream($messages) as $delta) {
                $text = $parser->push($delta);
                if ('' === $text) {
                    continue;
                }

                $assistantText .= $text;

                yield ChatReplyEvent::text($text);
            }
        } catch (BlockedInputException) {
            // The prefilter stopped the request before any model call; answer statically instead.
            $reply = $this->translator->trans('prefilter.reply', [], 'guarded_chat', $locale);
            $this->appendAssistantTurn($session, $reply, $now);

            yield ChatReplyEvent::text($reply);

            return;
        }

        $rest = $parser->finish();
        if ('' !== $rest) {
            $assistantText .= $rest;

            yield ChatReplyEvent::text($rest);
        }

        foreach ($parser->actions() as $action) {
            yield ChatReplyEvent::action($action);
        }

        $this->appendAssistantTurn($session, $assistantText, $now, $snippets);
    }

    /** @param list<KnowledgeSnippetInterface> $snippets the knowledge that fed this answer */
    private function appendAssistantTurn(ChatSession $session, string $text, \DateTimeImmutable $now, array $snippets = []): void
    {
        $session->appendTurn(ChatRole::Assistant, $text, $now, $this->limits);
        $this->sessions->save($session);
        // Null, not an empty list, when no knowledge was involved, so "no knowledge" stays
        // distinguishable from "knowledge was involved but empty".
        $ids = array_map(static fn (KnowledgeSnippetInterface $snippet): string => $snippet->getReferenceId(), $snippets);
        $this->transcript->append($session, ChatRole::Assistant, $text, $now, [] === $ids ? null : $ids);
    }
}
