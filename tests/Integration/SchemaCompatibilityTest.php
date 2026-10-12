<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\SchemaTool;
use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;

/**
 * Apps that already run the chat tables from before this bundle existed switch over by accepting only an
 * empty `doctrine:migrations:diff`. This pins the mapping to that schema: table, column and index names
 * plus the column types and options that end up in the DDL.
 *
 * The test kernel keeps Doctrine's default naming strategy on purpose: the explicit column names in
 * the entities must make the result independent of whatever strategy the host app configures.
 */
final class SchemaCompatibilityTest extends KernelTestCase
{
    public function testChatSessionMatchesTheExistingTable(): void
    {
        self::assertSame($this->sorted([
            'columns' => [
                'id' => ['type' => 'uuid', 'nullable' => false],
                'created_at' => ['type' => 'datetime_immutable', 'nullable' => false],
                'expires_at' => ['type' => 'datetime_immutable', 'nullable' => false],
                'last_message_at' => ['type' => 'datetime_immutable', 'nullable' => true],
                'message_count' => ['type' => 'integer', 'nullable' => false],
                'history' => ['type' => 'json', 'nullable' => false, 'default' => '[]'],
            ],
            'indexes' => [],
            'foreignKeys' => [],
        ]), $this->describe('chat_session'));
    }

    public function testChatMessageMatchesTheExistingTable(): void
    {
        self::assertSame($this->sorted([
            'columns' => [
                'id' => ['type' => 'uuid', 'nullable' => false],
                'session_id' => ['type' => 'uuid', 'nullable' => false],
                'role' => ['type' => 'string', 'nullable' => false, 'length' => 20],
                'content' => ['type' => 'text', 'nullable' => false],
                'created_at' => ['type' => 'datetime_immutable', 'nullable' => false],
                'context_snippet_ids' => ['type' => 'json', 'nullable' => true, 'jsonb' => true],
            ],
            'indexes' => [
                'idx_chat_message_created_at' => ['columns' => ['created_at'], 'unique' => false, 'where' => null],
                'idx_chat_message_session' => ['columns' => ['session_id'], 'unique' => false, 'where' => null],
            ],
            'foreignKeys' => [['columns' => ['session_id'], 'table' => 'chat_session', 'references' => ['id'], 'onDelete' => 'CASCADE']],
        ]), $this->describe('chat_message'));
    }

    public function testChatPromptMatchesTheExistingTableIncludingThePartialUniqueIndex(): void
    {
        self::assertSame($this->sorted([
            'columns' => [
                'id' => ['type' => 'uuid', 'nullable' => false],
                'content' => ['type' => 'text', 'nullable' => false],
                'created_at' => ['type' => 'datetime_immutable', 'nullable' => false],
                'active' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
            ],
            'indexes' => [
                'idx_chat_prompt_active' => ['columns' => ['active'], 'unique' => false, 'where' => null],
                'uniq_chat_prompt_active' => ['columns' => ['active'], 'unique' => true, 'where' => '(active = true)'],
            ],
            'foreignKeys' => [],
        ]), $this->describe('chat_prompt'));
    }

    public function testNoOtherTablesAreMapped(): void
    {
        $this->boot(createSchema: false);

        self::assertSame(['chat_message', 'chat_prompt', 'chat_session'], $this->tableNames());
    }

    /**
     * Column order is not part of the contract, only the set of columns is.
     *
     * @param array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>} $description
     *
     * @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>}
     */
    private function sorted(array $description): array
    {
        ksort($description['columns']);

        return $description;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = array_map(static fn (Table $table): string => $table->getName(), $this->schemaTables());
        sort($names);

        return $names;
    }

    /** @return list<Table> */
    private function schemaTables(): array
    {
        $em = $this->entityManager();

        return (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata())->getTables();
    }

    /** @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>} */
    private function describe(string $tableName): array
    {
        $this->boot(createSchema: false);
        $table = null;
        foreach ($this->schemaTables() as $candidate) {
            if ($candidate->getName() === $tableName) {
                $table = $candidate;
            }
        }
        self::assertNotNull($table, sprintf('Table "%s" is not mapped.', $tableName));

        $columns = [];
        foreach ($table->getColumns() as $column) {
            $description = ['type' => Type::lookupName($column->getType()), 'nullable' => !$column->getNotnull()];
            if (null !== $column->getDefault()) {
                $description['default'] = $column->getDefault();
            }
            if ('string' === $description['type']) {
                $description['length'] = $column->getLength();
            }
            if (true === ($column->getPlatformOptions()['jsonb'] ?? null)) {
                $description['jsonb'] = true;
            }
            $columns[$column->getName()] = $description;
        }

        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }
            $where = $index->getOptions()['where'] ?? null;
            $indexes[$index->getName()] = ['columns' => $index->getColumns(), 'unique' => $index->isUnique(), 'where' => $where];
        }
        ksort($indexes);
        ksort($columns);

        $foreignKeys = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = [
                'columns' => $foreignKey->getLocalColumns(),
                'table' => $foreignKey->getForeignTableName(),
                'references' => $foreignKey->getForeignColumns(),
                'onDelete' => $foreignKey->getOption('onDelete'),
            ];
        }

        return ['columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys];
    }
}
