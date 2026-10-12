<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/** Boots {@see TestKernel} with a fresh schema per test. */
abstract class KernelTestCase extends TestCase
{
    private ?TestKernel $kernel = null;

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        FakeGateway::reset();
        $this->errorHandler = self::currentErrorHandler();
        $this->exceptionHandler = self::currentExceptionHandler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        // Booting a debug kernel registers Symfony's error and exception handlers. Newer
        // FrameworkBundle versions remove them again on shutdown, older ones do not, and PHPUnit
        // flags a test that leaves its handlers behind as risky.
        for ($i = 0; $i < 10 && self::currentErrorHandler() !== $this->errorHandler; ++$i) {
            restore_error_handler();
        }
        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private static function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(static fn (\Throwable $e) => null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * @param array<string, mixed>                  $bundleConfig
     * @param \Closure(ContainerBuilder): void|null $containerHook
     */
    protected function boot(array $bundleConfig = [], ?\Closure $containerHook = null, bool $createSchema = true): Kernel
    {
        $this->kernel = new TestKernel($bundleConfig, $containerHook, $containerHook instanceof \Closure ? spl_object_hash($containerHook) : 'default');
        $this->kernel->boot();

        if ($createSchema) {
            $em = $this->entityManager();
            $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            (new SchemaTool($em))->createSchema($metadata);
            // DBAL's SQLite platform drops the partial predicate from the unique index, which would
            // forbid more than one inactive prompt. Recreate it the way PostgreSQL has it.
            $em->getConnection()->executeStatement('DROP INDEX uniq_chat_prompt_active');
            $em->getConnection()->executeStatement('CREATE UNIQUE INDEX uniq_chat_prompt_active ON chat_prompt (active) WHERE active = 1');
        }

        return $this->kernel;
    }

    /**
     * @param array<string, mixed>                  $bundleConfig
     * @param \Closure(ContainerBuilder): void|null $containerHook
     */
    protected function client(array $bundleConfig = [], ?\Closure $containerHook = null): KernelBrowser
    {
        $this->boot($bundleConfig, $containerHook);
        $client = $this->container()->get('test.client');
        \assert($client instanceof KernelBrowser);
        // The in-memory database lives in the booted kernel: rebooting between requests would wipe it.
        $client->disableReboot();

        return $client;
    }

    protected function container(): ContainerInterface
    {
        \assert($this->kernel instanceof TestKernel);

        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    protected function entityManager(): EntityManagerInterface
    {
        $em = $this->container()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->container()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
