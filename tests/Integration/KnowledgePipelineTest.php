<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Entity\ChatKnowledgeSnippet;
use Stejac\GuardedChatBundle\Entity\ChatMessage;
use Stejac\GuardedChatBundle\Enum\ChatKnowledgeSource;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Knowledge\NullRetriever;
use Stejac\GuardedChatBundle\Knowledge\PostgresFullTextRetriever;
use Stejac\GuardedChatBundle\Repository\ChatKnowledgeSnippetRepository;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Tests\Support\FakeGateway;
use Stejac\GuardedChatBundle\Tests\Support\FakeRetriever;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Uid\Uuid;

final class KnowledgePipelineTest extends KernelTestCase
{
    public function testWithoutAConfiguredRetrieverThereIsNoKnowledgeAndNoPostgresIsNeeded(): void
    {
        $client = $this->client();
        $this->post($client, '/chat/session', []);

        $this->post($client, '/chat/message', ['message' => 'What do you offer?']);
        $client->getResponse()->sendContent();

        self::assertInstanceOf(NullRetriever::class, $this->service(KnowledgeRetrieverInterface::class));
        self::assertStringNotContainsString('Verified information', FakeGateway::messages(0)[0]['content']);
        $answer = $this->entityManager()->getRepository(ChatMessage::class)->findOneBy(['role' => ChatRole::Assistant]);
        self::assertNotNull($answer);
        self::assertNull($answer->getContextSnippetIds());
    }

    public function testThePostgresRetrieverIsSelectedByKeyword(): void
    {
        $this->boot(['knowledge' => ['retriever' => 'postgres_full_text']]);

        self::assertInstanceOf(PostgresFullTextRetriever::class, $this->service(KnowledgeRetrieverInterface::class));
    }

    public function testRetrievedKnowledgeGoesIntoTheSystemPromptAndIsRecordedWithTheAnswer(): void
    {
        $client = $this->clientWithRetriever(['subject' => 'Acme Corp']);
        $first = $this->persistSnippet('Stack', 'Acme works with Symfony and PostgreSQL.');
        $second = $this->persistSnippet('Contact', 'Use the contact form.', 'How do I reach Acme?', 'https://example.com/contact');
        FakeRetriever::$snippets = [$first, $second];
        $this->post($client, '/chat/session', []);

        $this->post($client, '/chat/message', ['message' => 'What stack does Acme use?']);
        $client->getResponse()->sendContent();

        $system = FakeGateway::messages(0)[0]['content'];
        self::assertStringContainsString('Verified information about Acme Corp.', $system);
        self::assertStringContainsString('- Stack: Acme works with Symfony and PostgreSQL.', $system);
        self::assertStringContainsString('- Contact: (Typical question: How do I reach Acme?) Use the contact form. [Source: https://example.com/contact]', $system);

        $messages = $this->entityManager()->getRepository(ChatMessage::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
        self::assertCount(2, $messages);
        self::assertNull($messages[0]->getContextSnippetIds(), 'the visitor turn carries no knowledge ids');
        self::assertSame([$first->getReferenceId(), $second->getReferenceId()], $messages[1]->getContextSnippetIds());
    }

    public function testTheRetrieverIsAskedWithTheVisitorQuestionAndTheConfiguredCap(): void
    {
        $client = $this->clientWithRetriever(['max_snippets' => 2]);
        $this->post($client, '/chat/session', []);

        $this->post($client, '/chat/message', ['message' => 'What stack do you use?']);

        self::assertSame([['question' => 'What stack do you use?', 'limit' => 2]], FakeRetriever::$calls);
    }

    public function testWithoutAMatchThePromptStaysUntouchedAndNoIdsAreRecorded(): void
    {
        $client = $this->clientWithRetriever();
        $this->post($client, '/chat/session', []);

        $this->post($client, '/chat/message', ['message' => 'Quantum physics?']);
        $client->getResponse()->sendContent();

        self::assertStringNotContainsString('Verified information', FakeGateway::messages(0)[0]['content']);
        $answer = $this->entityManager()->getRepository(ChatMessage::class)->findOneBy(['role' => ChatRole::Assistant]);
        self::assertNotNull($answer);
        self::assertNull($answer->getContextSnippetIds());
    }

    public function testABlockedMessageNeverTouchesTheModelAndRecordsNoKnowledge(): void
    {
        $client = $this->clientWithRetriever();
        FakeRetriever::$snippets = [$this->persistSnippet('Stack', 'Symfony.')];
        $this->post($client, '/chat/session', []);

        $this->post($client, '/chat/message', ['message' => 'jailbreak']);

        self::assertSame([], FakeGateway::$requests);
        $answer = $this->entityManager()->getRepository(ChatMessage::class)->findOneBy(['role' => ChatRole::Assistant]);
        self::assertNotNull($answer);
        self::assertNull($answer->getContextSnippetIds());
    }

    public function testSnippetUsageIsCountedPerSnippet(): void
    {
        $this->boot();
        $messages = $this->service(ChatMessageRepository::class);
        $session = $this->persistSession();
        $now = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $messages->append($session, ChatRole::User, 'question', $now);
        $messages->append($session, ChatRole::Assistant, 'a', $now, ['one', 'two']);
        $messages->append($session, ChatRole::Assistant, 'b', $now, ['one']);
        $messages->append($session, ChatRole::Assistant, 'c', $now);

        self::assertSame(['one' => 2, 'two' => 1], $this->service(ChatMessageRepository::class)->countSnippetUsage());
    }

    public function testRepositoryListsDormantSuggestionsFirstAndSavesAndRemoves(): void
    {
        $this->boot();
        $repository = $this->service(ChatKnowledgeSnippetRepository::class);
        $admin = new ChatKnowledgeSnippet('Admin', null, 'Active.', 1, null, ChatKnowledgeSource::Admin, new \DateTimeImmutable('2026-07-01'));
        $suggestion = new ChatKnowledgeSnippet('Suggested', null, 'Dormant.', 1, null, ChatKnowledgeSource::Api, new \DateTimeImmutable('2026-06-01'));
        $repository->save($admin);
        $repository->save($suggestion);

        self::assertSame(['Suggested', 'Admin'], array_map(static fn (ChatKnowledgeSnippet $s): string => $s->getTitle(), $repository->findAllForAdmin()));

        $repository->remove($suggestion);

        self::assertSame(['Admin'], array_map(static fn (ChatKnowledgeSnippet $s): string => $s->getTitle(), $repository->findAllForAdmin()));
    }

    public function testTheTextSearchConfigurationMustBeAPlainName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/text search configuration/');

        $this->boot(['knowledge' => ['text_search_config' => "german'; DROP TABLE chat_session; --"]]);
    }

    /** @param array<string, mixed> $knowledge settings of `guarded_chat.knowledge` besides the retriever */
    private function clientWithRetriever(array $knowledge = []): KernelBrowser
    {
        return $this->client(['knowledge' => ['retriever' => 'test.retriever', ...$knowledge]], static function (ContainerBuilder $builder): void {
            $builder->register('test.retriever', FakeRetriever::class);
        });
    }

    private function persistSnippet(string $title, string $content, ?string $question = null, ?string $sourceUrl = null): ChatKnowledgeSnippet
    {
        $snippet = new ChatKnowledgeSnippet($title, $question, $content, 1, $sourceUrl, ChatKnowledgeSource::Admin, new \DateTimeImmutable('2026-07-29 08:00:00'));
        $this->service(ChatKnowledgeSnippetRepository::class)->save($snippet);

        return $snippet;
    }

    private function persistSession(): \Stejac\GuardedChatBundle\Entity\ChatSession
    {
        $session = new \Stejac\GuardedChatBundle\Entity\ChatSession(Uuid::v4(), new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $this->service(\Stejac\GuardedChatBundle\Repository\ChatSessionRepository::class)->save($session);

        return $session;
    }

    /** @param array<string, mixed> $payload */
    private function post(KernelBrowser $client, string $path, array $payload): void
    {
        $client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
