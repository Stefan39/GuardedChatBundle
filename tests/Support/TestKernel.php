<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Stejac\GuardedChatBundle\GuardedChatBundle;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A minimal host app: FrameworkBundle, DoctrineBundle on in-memory SQLite, and the bundle wired to
 * {@see FakeGateway} through the generic Symfony AI platform. `$bundleConfig` is merged over the defaults.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed>                  $bundleConfig
     * @param \Closure(ContainerBuilder): void|null $containerHook extra service definitions for a test
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly ?\Closure $containerHook = null,
        private readonly string $variant = 'default',
        private readonly ?string $databaseUrl = null,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new GuardedChatBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/guarded-chat-bundle-tests/'.$this->variant.'-'.md5(serialize($this->bundleConfig).__DIR__.$this->databaseUrl).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/guarded-chat-bundle-tests/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        // Tests read the bundle's services directly, so make every one of them public.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // The in-memory limiter storage must survive the service reset between two requests,
                // otherwise a limit could never be exhausted inside one test.
                if ($container->hasDefinition('cache.rate_limiter')) {
                    $container->getDefinition('cache.rate_limiter')->clearTag('kernel.reset');
                }
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with((string) $definition->getClass(), 'Stejac\\GuardedChatBundle\\') || str_starts_with($id, 'guarded_chat.') || str_starts_with($id, 'limiter.guarded_chat')) {
                        $definition->setPublic(true);
                    }
                }
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_starts_with($id, 'Stejac\\GuardedChatBundle\\') || str_starts_with($id, 'guarded_chat.')) {
                        $alias->setPublic(true);
                    }
                }
            }
        });
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'default_locale' => 'en',
            'translator' => ['enabled' => true],
            'messenger' => ['enabled' => true],
            'cache' => ['pools' => ['cache.rate_limiter' => ['adapter' => 'cache.adapter.array']]],
        ]);
        $container->extension('doctrine', [
            'dbal' => null === $this->databaseUrl ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['url' => $this->databaseUrl],
            'orm' => [],
        ]);
        $container->extension('guarded_chat', array_replace([
            'platform' => 'test.platform',
            'model' => 'ok',
            'prompt' => ['default' => 'You are the assistant of an example shop.'],
            'actions' => ['contact' => ['marker' => '[[contact]]', 'instruction' => 'Output [[contact]] as the final token when the visitor should get in touch.']],
        ], $this->bundleConfig));

        $services = $container->services();
        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set('test.gateway_client', \Symfony\Component\HttpClient\MockHttpClient::class)->factory([FakeGateway::class, 'client']);
        $services->set('test.platform', \Symfony\AI\Platform\PlatformInterface::class)
            ->factory([Factory::class, 'createPlatform'])
            ->args(['http://gateway.test', 'test-key', service('test.gateway_client')])
            ->arg('$supportsEmbeddings', false)
            ->arg('$completionsPath', '/chat/completions');

        if (null !== $this->containerHook) {
            ($this->containerHook)($builder);
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@GuardedChatBundle/config/routes.php')->prefix('/chat');
    }
}
