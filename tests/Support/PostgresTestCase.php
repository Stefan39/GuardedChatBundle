<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Base for tests that need a real PostgreSQL. Point GUARDED_CHAT_TEST_DATABASE_URL at a throw-away
 * database (the public schema is dropped on every boot), e.g.
 * `postgresql://postgres:test@127.0.0.1:5432/guarded_chat_test?serverVersion=16`.
 * Without it the tests are skipped locally; CI provides a PostgreSQL service and must not skip them.
 */
abstract class PostgresTestCase extends KernelTestCase
{
    /**
     * @param array<string, mixed>                  $bundleConfig
     * @param \Closure(ContainerBuilder): void|null $containerHook
     */
    protected function bootPostgres(array $bundleConfig = [], ?\Closure $containerHook = null, bool $createSchema = true): Kernel
    {
        $url = getenv('GUARDED_CHAT_TEST_DATABASE_URL');
        if (false === $url || '' === $url) {
            if (false !== getenv('CI')) {
                self::fail('GUARDED_CHAT_TEST_DATABASE_URL must be set in CI: the PostgreSQL tests may not be skipped there.');
            }

            self::markTestSkipped('Set GUARDED_CHAT_TEST_DATABASE_URL to run the PostgreSQL tests.');
        }

        return $this->boot($bundleConfig, $containerHook, $createSchema, $url);
    }
}
