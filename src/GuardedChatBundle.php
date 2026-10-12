<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle;

use Cron\CronExpression;
use Stejac\GuardedChatBundle\Guard\AlwaysOnRuntimeSwitch;
use Stejac\GuardedChatBundle\Guard\BudgetExhaustionDetectorInterface;
use Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface;
use Stejac\GuardedChatBundle\Guard\ClientIdentifierResolverInterface;
use Stejac\GuardedChatBundle\Guard\HumanVerifierInterface;
use Stejac\GuardedChatBundle\Guard\NullHumanVerifier;
use Stejac\GuardedChatBundle\Guard\RateLimitBudgetExhaustionDetector;
use Stejac\GuardedChatBundle\Guard\RequestClientIdentifierResolver;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Knowledge\NullRetriever;
use Stejac\GuardedChatBundle\Knowledge\PostgresFullTextRetriever;
use Stejac\GuardedChatBundle\Prefilter\PrefilterInputProcessor;
use Stejac\GuardedChatBundle\Scheduler\ChatRetentionSchedule;
use Symfony\AI\Agent\Agent;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Scheduler\Schedule;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * @phpstan-type RateLimit array{limit: int, interval: string}
 * @phpstan-type Config array{
 *     enabled: bool,
 *     platform: string,
 *     model: string,
 *     max_output_tokens: int,
 *     max_message_length: int,
 *     secret: string,
 *     locales: list<string>,
 *     default_locale: string,
 *     session: array{lifetime: int, idle_timeout: int, max_messages: int, max_history: int},
 *     prompt: array{default: string, ai_transparency: bool, pinned_instructions: list<string>},
 *     actions: array<string, array{marker: string, instruction: string|null}>,
 *     prefilter: array{enabled: bool, phrases: list<string>},
 *     rate_limits: array{session: RateLimit, message: RateLimit, message_daily: RateLimit},
 *     knowledge: array{retriever: string|null, text_search_config: non-empty-string, max_snippets: int, subject: string|null},
 *     retention: array{days: int, schedule: array{enabled: bool, name: string, cron: string, timezone: string, state_cache: string|null}},
 *     runtime_switch: string|null,
 *     human_verifier: string|null,
 *     client_identifier_resolver: string|null,
 *     budget_exhaustion_detector: string|null,
 * }
 */
final class GuardedChatBundle extends AbstractBundle
{
    protected string $extensionAlias = 'guarded_chat';

    /** Extension points: configuration key => interface the key replaces the default implementation of. */
    private const array EXTENSION_POINTS = [
        'runtime_switch' => [ChatRuntimeSwitchInterface::class, AlwaysOnRuntimeSwitch::class],
        'human_verifier' => [HumanVerifierInterface::class, NullHumanVerifier::class],
        'client_identifier_resolver' => [ClientIdentifierResolverInterface::class, RequestClientIdentifierResolver::class],
        'budget_exhaustion_detector' => [BudgetExhaustionDetectorInterface::class, RateLimitBudgetExhaustionDetector::class],
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode()->children();

        $root->booleanNode('enabled')->defaultTrue()
            ->info('Hard kill switch (stage 1). Survives a broken database; bind it to an env var to switch the chat off with a deploy.')->end();
        $root->scalarNode('platform')->isRequired()->cannotBeEmpty()
            ->info('Service id of the Symfony AI platform, e.g. "ai.platform.generic.litellm".')->end();
        $root->scalarNode('model')->isRequired()->cannotBeEmpty()
            ->info('Model name sent to the platform.')->end();
        $root->integerNode('max_output_tokens')->defaultValue(300)->min(1)->end();
        $root->integerNode('max_message_length')->defaultValue(500)->min(1)
            ->info('Longest visitor message in characters.')->end();
        $root->scalarNode('secret')->defaultValue('%kernel.secret%')->cannotBeEmpty()
            ->info('Secret that signs session tokens.')->end();
        $root->arrayNode('locales')->scalarPrototype()->end()->defaultValue(['de', 'en'])->requiresAtLeastOneElement()
            ->info('Locales the widget may send; translations ship for de and en.')->end();
        $root->scalarNode('default_locale')->defaultValue('en')->cannotBeEmpty()->end();

        $session = $root->arrayNode('session')->addDefaultsIfNotSet()->children();
        $session->integerNode('lifetime')->defaultValue(7200)->min(1)->info('Hard session lifetime in seconds.')->end();
        $session->integerNode('idle_timeout')->defaultValue(1800)->min(1)->info('Seconds without a message before the session expires.')->end();
        $session->integerNode('max_messages')->defaultValue(20)->min(1)->info('Visitor messages per session.')->end();
        $session->integerNode('max_history')->defaultValue(6)->min(1)->info('Turns kept as the model\'s context window.')->end();

        $prompt = $root->arrayNode('prompt')->addDefaultsIfNotSet()->children();
        $prompt->scalarNode('default')->isRequired()->cannotBeEmpty()
            ->info('Prompt of last resort, used while no prompt version is stored. Describes your assistant; the bundle ships no default text.')->end();
        $prompt->booleanNode('ai_transparency')->defaultTrue()
            ->info('Append the instruction to always disclose being an AI (Art. 50 EU AI Act).')->end();
        $prompt->arrayNode('pinned_instructions')->scalarPrototype()->end()->defaultValue([])
            ->info('Instructions appended to every prompt version, e.g. form of address or plain-text output.')->end();

        $root->arrayNode('actions')->useAttributeAsKey('type')->defaultValue([])
            ->info('Markers the model may emit; each occurrence becomes an SSE "action" event carrying the type.')
            ->arrayPrototype()->children()
                ->scalarNode('marker')->isRequired()->cannotBeEmpty()->example('[[contact]]')->end()
                ->scalarNode('instruction')->defaultNull()->info('Told to the model so it knows when to emit the marker.')->end()
            ->end()->end()->end();

        $prefilter = $root->arrayNode('prefilter')->addDefaultsIfNotSet()->children();
        $prefilter->booleanNode('enabled')->defaultTrue()->end();
        $prefilter->arrayNode('phrases')->scalarPrototype()->end()->defaultValue(self::defaultPhrases())
            ->info('Phrases that block a message before the model is called. Replaces the default list; matched as whole words, case-insensitive.')->end();

        $knowledge = $root->arrayNode('knowledge')->addDefaultsIfNotSet()->children();
        $knowledge->scalarNode('retriever')->defaultNull()
            ->info('Where answers get their knowledge from: "postgres_full_text" (needs PostgreSQL), the service id of your KnowledgeRetrieverInterface, or null for none.')->end();
        $knowledge->scalarNode('text_search_config')->defaultValue('german')->cannotBeEmpty()
            ->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || 1 !== preg_match('/^[a-z_]+$/', $value))
            ->thenInvalid('Must be the name of a PostgreSQL text search configuration, e.g. "german" or "english".')->end()
            ->info('PostgreSQL text search configuration used by the "postgres_full_text" retriever.')->end();
        $knowledge->integerNode('max_snippets')->defaultValue(4)->min(1)
            ->info('Most snippets that go into one answer; bounds the prompt size.')->end();
        $knowledge->scalarNode('subject')->defaultNull()
            ->info('Whom or what the knowledge is about, named in the prompt block ("Verified information about <subject>").')->end();

        $limits = $root->arrayNode('rate_limits')->addDefaultsIfNotSet()->children();
        foreach (['session' => [5, '1 hour'], 'message' => [8, '1 minute'], 'message_daily' => [50, '1 day']] as $name => [$limit, $interval]) {
            $limits->arrayNode($name)->addDefaultsIfNotSet()->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->scalarNode('interval')->defaultValue($interval)->cannotBeEmpty()->end()
            ->end()->end();
        }

        $retention = $root->arrayNode('retention')->addDefaultsIfNotSet()->children();
        $retention->integerNode('days')->defaultValue(90)->min(1)->info('Sessions and their messages are deleted after this many days.')->end();
        $schedule = $retention->arrayNode('schedule')->addDefaultsIfNotSet()->children();
        $schedule->booleanNode('enabled')->defaultFalse()->info('Register a recurring prune on a Symfony Scheduler schedule.')->end();
        $schedule->scalarNode('name')->defaultValue('default')->info('Name of the schedule to add the prune to.')->end();
        $schedule->scalarNode('cron')->defaultValue('30 3 * * *')->end();
        $schedule->scalarNode('timezone')->defaultValue('UTC')->end();
        $schedule->scalarNode('state_cache')->defaultNull()->info('Service id of a cache pool that keeps the schedule state across deploys.')->end();

        foreach (self::EXTENSION_POINTS as $key => [$interface]) {
            $root->scalarNode($key)->defaultNull()->info(sprintf('Service id of your %s implementation.', substr($interface, (int) strrpos($interface, '\\') + 1)))->end();
        }
    }

    /**
     * @param Config $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        $parameters->set('guarded_chat.enabled', $config['enabled']);
        $parameters->set('guarded_chat.model', $config['model']);
        $parameters->set('guarded_chat.max_output_tokens', $config['max_output_tokens']);
        $parameters->set('guarded_chat.max_message_length', $config['max_message_length']);
        $parameters->set('guarded_chat.secret', $config['secret']);
        $parameters->set('guarded_chat.locales', $config['locales']);
        $parameters->set('guarded_chat.default_locale', $config['default_locale']);
        foreach ($config['session'] as $key => $value) {
            $parameters->set('guarded_chat.session.'.$key, $value);
        }
        $parameters->set('guarded_chat.prompt.default', $config['prompt']['default']);
        $parameters->set('guarded_chat.prompt.ai_transparency', $config['prompt']['ai_transparency']);
        $parameters->set('guarded_chat.prompt.pinned_instructions', $config['prompt']['pinned_instructions']);
        $parameters->set('guarded_chat.action_markers', array_map(static fn (array $action): string => $action['marker'], $config['actions']));
        $parameters->set('guarded_chat.action_instructions', array_filter(array_map(static fn (array $action): ?string => $action['instruction'], $config['actions'])));
        $parameters->set('guarded_chat.prefilter.enabled', $config['prefilter']['enabled']);
        $parameters->set('guarded_chat.prefilter.phrases', $config['prefilter']['phrases']);
        $parameters->set('guarded_chat.knowledge.text_search_config', $config['knowledge']['text_search_config']);
        $parameters->set('guarded_chat.knowledge.max_snippets', $config['knowledge']['max_snippets']);
        $parameters->set('guarded_chat.knowledge.subject', $config['knowledge']['subject']);
        $parameters->set('guarded_chat.retention.days', $config['retention']['days']);

        $container->import('../config/services.php');

        $services = $container->services();
        $services->alias('guarded_chat.platform', $config['platform']);
        $services->set('guarded_chat.agent', Agent::class)->args([
            service('guarded_chat.platform'),
            $config['model'],
            $config['prefilter']['enabled'] ? [service(PrefilterInputProcessor::class)] : [],
        ]);

        $retriever = $config['knowledge']['retriever'];
        $services->alias(KnowledgeRetrieverInterface::class, match ($retriever) {
            null => NullRetriever::class,
            'postgres_full_text' => PostgresFullTextRetriever::class,
            default => $retriever,
        });

        foreach (self::EXTENSION_POINTS as $key => [$interface, $default]) {
            $services->alias($interface, $config[$key] ?? $default);
            if (null === $config[$key]) {
                $services->set($default)->private();
            }
        }

        $schedule = $config['retention']['schedule'];
        if ($schedule['enabled']) {
            if (!class_exists(Schedule::class) || !class_exists(CronExpression::class)) {
                throw new \LogicException('guarded_chat.retention.schedule needs symfony/scheduler and dragonmantank/cron-expression. Run "composer require symfony/scheduler dragonmantank/cron-expression".');
            }

            $services->set(ChatRetentionSchedule::class)
                ->args([$schedule['cron'], $schedule['timezone'], null === $schedule['state_cache'] ? null : service($schedule['state_cache'])])
                ->tag('scheduler.schedule_provider', ['name' => $schedule['name']]);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundles = $builder->getParameter('kernel.bundles');
        \assert(\is_array($bundles));

        if (isset($bundles['DoctrineBundle'])) {
            $container->extension('doctrine', ['orm' => ['mappings' => ['GuardedChat' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => __DIR__.'/Entity',
                'prefix' => 'Stejac\GuardedChatBundle\Entity',
                'alias' => 'GuardedChat',
            ]]]]);
        }

        // The limiters are registered from this bundle's own configuration, so the defaults need no
        // framework.rate_limiter block in the app.
        $extension = $builder->getExtension('guarded_chat');
        $configuration = $extension instanceof ConfigurationExtensionInterface ? $extension->getConfiguration([], $builder) : null;
        if (null === $configuration) {
            return;
        }

        /** @var Config $config */
        $config = (new Processor())->processConfiguration($configuration, $builder->getExtensionConfig('guarded_chat'));
        $limits = $config['rate_limits'];
        $container->extension('framework', ['rate_limiter' => [
            'guarded_chat_session' => ['policy' => 'sliding_window', 'limit' => $limits['session']['limit'], 'interval' => $limits['session']['interval']],
            'guarded_chat_message' => ['policy' => 'token_bucket', 'limit' => $limits['message']['limit'], 'rate' => ['interval' => $limits['message']['interval'], 'amount' => $limits['message']['limit']]],
            'guarded_chat_message_daily' => ['policy' => 'sliding_window', 'limit' => $limits['message_daily']['limit'], 'interval' => $limits['message_daily']['interval']],
        ]]);
    }

    /** @return list<string> */
    private static function defaultPhrases(): array
    {
        return [
            'ignore previous instructions',
            'ignore all previous instructions',
            'ignoriere alle vorherigen anweisungen',
            'ignoriere deine anweisungen',
            'system prompt',
            'systemprompt',
            'you are now',
            'du bist jetzt',
            'act as',
            'agiere als',
            'pretend to be',
            'tu so als ob',
            'dan mode',
            'jailbreak',
            'developer mode',
        ];
    }
}
