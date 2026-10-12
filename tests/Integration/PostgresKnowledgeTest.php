<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Composer\InstalledVersions;
use Doctrine\ORM\Tools\SchemaTool;
use Stejac\GuardedChatBundle\Entity\ChatKnowledgeSnippet;
use Stejac\GuardedChatBundle\Entity\ChatMessage;
use Stejac\GuardedChatBundle\Enum\ChatKnowledgeSource;
use Stejac\GuardedChatBundle\Enum\ChatRole;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Repository\ChatKnowledgeSnippetRepository;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Tests\Support\FakeGateway;
use Stejac\GuardedChatBundle\Tests\Support\PostgresTestCase;

/** Runs against a real PostgreSQL: full-text search, the jsonb column and the generated DDL. */
final class PostgresKnowledgeTest extends PostgresTestCase
{
    public function testFindsASnippetByKeyword(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        $this->snippet('Symfony', null, 'Acme builds applications with Symfony and Doctrine.');
        $this->snippet('Garden', null, 'A completely different topic.');

        $found = $this->retriever()->retrieve('What does Acme build with Symfony?', 4);

        self::assertCount(1, $found);
        self::assertSame('Symfony', $found[0]->getTitle());
    }

    public function testIgnoresInactiveSnippets(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        $snippet = $this->snippet('Symfony', null, 'Acme builds with Symfony.');
        $snippet->deactivate();
        $this->entityManager()->flush();

        self::assertSame([], $this->retriever()->retrieve('Symfony', 4));
    }

    public function testHigherPriorityRanksFirst(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        $this->snippet('Low', null, 'Symfony', 1);
        $this->snippet('High', 'What does Acme do with Symfony?', 'Symfony', 5);

        self::assertSame('High', $this->retriever()->retrieve('Symfony', 4)[0]->getTitle());
    }

    public function testRespectsTheLimit(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        for ($i = 0; $i < 6; ++$i) {
            $this->snippet('Title '.$i, null, 'Symfony development number '.$i);
        }

        self::assertCount(4, $this->retriever()->retrieve('Symfony', 4));
        self::assertCount(2, $this->retriever()->retrieve('Symfony', 2));
    }

    public function testAQuestionMadeOfStopWordsFindsNothing(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        $this->snippet('Symfony', null, 'Acme builds with Symfony.');

        self::assertSame([], $this->retriever()->retrieve('and or but', 4));
    }

    public function testASingleSharedTermIsEnoughBecauseTheQueryIsOrRewritten(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english']]);
        $this->snippet('Stack', null, 'Acme works with Symfony.');

        self::assertCount(1, $this->retriever()->retrieve('Which tools does the whole team around Acme prefer in everyday work?', 4));
    }

    public function testTheTextSearchConfigurationDecidesTheStemming(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'german']]);
        $this->snippet('Projects', null, 'Wir haben zahlreiche Projekte umgesetzt.');

        // The German Snowball stemmer normalises number, not word class: "Projekte"/"Projekt" collapse
        // to one lexeme, "Entwicklung"/"entwickelt" do not.
        self::assertCount(1, $this->retriever()->retrieve('Was für ein Projekt macht ihr?', 4));
    }

    public function testNounAndVerbFormsAreNotBridged(): void
    {
        $this->bootPostgres(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'german']]);
        $this->snippet('Development', null, 'Wir übernehmen die Entwicklung von Webanwendungen.');

        self::assertSame([], $this->retriever()->retrieve('Wer entwickelt das?', 4));
    }

    public function testAnAnswerCarriesTheSnippetIdsThroughTheJsonbColumn(): void
    {
        $client = $this->bootedClient(['knowledge' => ['retriever' => 'postgres_full_text', 'text_search_config' => 'english', 'subject' => 'Acme']]);
        $snippet = $this->snippet('Stack', null, 'Acme works with Symfony and PostgreSQL.');
        $client->request('POST', '/chat/session', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $client->request('POST', '/chat/message', [], [], ['CONTENT_TYPE' => 'application/json'], '{"message":"Which stack does Acme use?","locale":"en"}');
        $client->getResponse()->sendContent();

        self::assertStringContainsString('- Stack: Acme works with Symfony and PostgreSQL.', FakeGateway::messages(0)[0]['content']);
        $this->entityManager()->clear();
        $answer = $this->entityManager()->getRepository(ChatMessage::class)->findOneBy(['role' => ChatRole::Assistant]);
        self::assertNotNull($answer);
        self::assertSame([$snippet->getReferenceId()], $answer->getContextSnippetIds());
        self::assertSame([$snippet->getReferenceId() => 1], $this->service(ChatMessageRepository::class)->countSnippetUsage());
        // jsonb, not json: what the column type is on the database side.
        self::assertSame('jsonb', $this->entityManager()->getConnection()->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'chat_message' AND column_name = 'context_snippet_ids'"));
    }

    public function testTheGeneratedDdlMatchesTheTablesAlreadyInProduction(): void
    {
        // DBAL 3 adds `COMMENT ON COLUMN ... (DC2Type:...)` statements; the tables to match were created with DBAL 4.
        if (version_compare(InstalledVersions::getVersion('doctrine/dbal') ?? '0', '4.0.0', '<')) {
            self::markTestSkipped('The expected DDL is what DBAL 4 generates.');
        }

        $this->bootPostgres(createSchema: false);
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('DROP SCHEMA public CASCADE');
        $em->getConnection()->executeStatement('CREATE SCHEMA public');

        $sql = (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        // Left out: the foreign key constraint, whose name is generated here while the existing table
        // carries a hand-written one (Doctrine's schema comparison ignores constraint names). Also
        // different, and irrelevant to a diff: chat_message lists session_id last instead of second.
        $statements = array_values(array_filter($sql, static fn (string $statement): bool => !str_starts_with($statement, 'ALTER TABLE')));
        sort($statements);

        $expected = [
            'CREATE INDEX idx_chat_knowledge_active ON chat_knowledge_snippet (active)',
            'CREATE INDEX idx_chat_message_created_at ON chat_message (created_at)',
            'CREATE INDEX idx_chat_message_session ON chat_message (session_id)',
            'CREATE INDEX idx_chat_prompt_active ON chat_prompt (active)',
            'CREATE TABLE chat_knowledge_snippet (id UUID NOT NULL, title VARCHAR(200) NOT NULL, question TEXT DEFAULT NULL, content TEXT NOT NULL, priority SMALLINT DEFAULT 1 NOT NULL, source_url VARCHAR(500) DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, source VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))',
            'CREATE TABLE chat_message (id UUID NOT NULL, role VARCHAR(20) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, context_snippet_ids JSONB DEFAULT NULL, session_id UUID NOT NULL, PRIMARY KEY (id))',
            'CREATE TABLE chat_prompt (id UUID NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, active BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))',
            'CREATE TABLE chat_session (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, message_count INT NOT NULL, history JSON DEFAULT \'[]\' NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX uniq_chat_prompt_active ON chat_prompt (active) WHERE (active = true)',
        ];

        self::assertSame($expected, $statements);
    }

    private function retriever(): KnowledgeRetrieverInterface
    {
        return $this->service(KnowledgeRetrieverInterface::class);
    }

    /** @param array<string, mixed> $config */
    private function bootedClient(array $config): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $this->bootPostgres($config);
        $client = $this->container()->get('test.client');
        \assert($client instanceof \Symfony\Bundle\FrameworkBundle\KernelBrowser);
        $client->disableReboot();

        return $client;
    }

    private function snippet(string $title, ?string $question, string $content, int $priority = 1): ChatKnowledgeSnippet
    {
        $snippet = new ChatKnowledgeSnippet($title, $question, $content, $priority, null, ChatKnowledgeSource::Admin, new \DateTimeImmutable('2026-07-29 08:00:00'));
        $this->service(ChatKnowledgeSnippetRepository::class)->save($snippet);

        return $snippet;
    }
}
