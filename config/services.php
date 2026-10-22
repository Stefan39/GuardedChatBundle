<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stejac\GuardedChatBundle\Command\PruneChatSessionsCommand;
use Stejac\GuardedChatBundle\Controller\ChatController;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Guard\BudgetExhaustionDetectorInterface;
use Stejac\GuardedChatBundle\Guard\ClientIdentifierResolverInterface;
use Stejac\GuardedChatBundle\Http\ChatRateLimiter;
use Stejac\GuardedChatBundle\Internal\AiStreamAdapter;
use Stejac\GuardedChatBundle\Knowledge\ChatKnowledgeFormatter;
use Stejac\GuardedChatBundle\Knowledge\KnowledgeRetrieverInterface;
use Stejac\GuardedChatBundle\Knowledge\NullRetriever;
use Stejac\GuardedChatBundle\Knowledge\PostgresFullTextRetriever;
use Stejac\GuardedChatBundle\MessageHandler\PruneChatSessionsMessageHandler;
use Stejac\GuardedChatBundle\Prefilter\OffTopicPrefilter;
use Stejac\GuardedChatBundle\Prefilter\PrefilterInputProcessor;
use Stejac\GuardedChatBundle\Repository\ChatKnowledgeSnippetRepository;
use Stejac\GuardedChatBundle\Repository\ChatMessageRepository;
use Stejac\GuardedChatBundle\Repository\ChatPromptRepository;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Security\ChatSessionTokenSigner;
use Stejac\GuardedChatBundle\Service\ChatAvailability;
use Stejac\GuardedChatBundle\Service\ChatMessageHandler;
use Stejac\GuardedChatBundle\Service\ChatPromptProvider;
use Stejac\GuardedChatBundle\Service\ChatSessionPruner;
use Stejac\GuardedChatBundle\Service\ChatSessionStarter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    foreach ([ChatSessionRepository::class, ChatMessageRepository::class, ChatPromptRepository::class, ChatKnowledgeSnippetRepository::class] as $repository) {
        $services->set($repository)->args([service('doctrine')])->tag('doctrine.repository_service');
    }

    $services->set(SessionLimits::class)->args([
        param('guarded_chat.session.lifetime'),
        param('guarded_chat.session.idle_timeout'),
        param('guarded_chat.session.max_messages'),
        param('guarded_chat.session.max_history'),
    ]);

    $services->set(ChatSessionTokenSigner::class)->args([param('guarded_chat.secret'), service(ClockInterface::class)]);
    $services->set(ChatSessionStarter::class)->args([
        service(ChatSessionRepository::class),
        service(ChatSessionTokenSigner::class),
        service(ClockInterface::class),
        service(SessionLimits::class),
    ]);
    $services->set(ChatAvailability::class)->args([
        service(Stejac\GuardedChatBundle\Guard\ChatRuntimeSwitchInterface::class),
        param('guarded_chat.enabled'),
    ]);
    $services->set(ChatPromptProvider::class)->args([
        service(ChatPromptRepository::class),
        service(TranslatorInterface::class),
        param('guarded_chat.prompt.default'),
        param('guarded_chat.prompt.ai_transparency'),
        param('guarded_chat.prompt.pinned_instructions'),
        param('guarded_chat.action_instructions'),
    ]);

    $services->set(NullRetriever::class);
    $services->set(PostgresFullTextRetriever::class)->args([
        service(ChatKnowledgeSnippetRepository::class),
        param('guarded_chat.knowledge.text_search_config'),
    ]);
    $services->set(ChatKnowledgeFormatter::class)->args([
        service(TranslatorInterface::class),
        param('guarded_chat.knowledge.subject'),
    ]);

    $services->set(OffTopicPrefilter::class)->args([param('guarded_chat.prefilter.phrases')]);
    $services->set(PrefilterInputProcessor::class)->args([service(OffTopicPrefilter::class)]);

    $services->set(AiStreamAdapter::class)->args([
        service('guarded_chat.agent'),
        service(BudgetExhaustionDetectorInterface::class),
        param('guarded_chat.max_output_tokens'),
    ]);

    $services->set(ChatMessageHandler::class)->args([
        service(AiStreamAdapter::class),
        service(ChatSessionRepository::class),
        service(ChatMessageRepository::class),
        service(ChatPromptProvider::class),
        service(TranslatorInterface::class),
        service(SessionLimits::class),
        service(KnowledgeRetrieverInterface::class),
        service(ChatKnowledgeFormatter::class),
        param('guarded_chat.knowledge.max_snippets'),
        param('guarded_chat.action_markers'),
    ]);

    $services->set(ChatRateLimiter::class)->args([service(ClientIdentifierResolverInterface::class)]);
    $services->set(ChatController::class)->public()->args([
        service(ChatSessionStarter::class),
        service(ChatSessionRepository::class),
        service(ChatSessionTokenSigner::class),
        service(ChatMessageHandler::class),
        service(ClockInterface::class),
        service(LoggerInterface::class),
        service(ChatAvailability::class),
        service(Stejac\GuardedChatBundle\Guard\HumanVerifierInterface::class),
        service(ChatRateLimiter::class),
        service(TranslatorInterface::class),
        service(SessionLimits::class),
        service('limiter.guarded_chat_session'),
        service('limiter.guarded_chat_message'),
        service('limiter.guarded_chat_message_daily'),
        param('guarded_chat.locales'),
        param('guarded_chat.default_locale'),
        param('guarded_chat.max_message_length'),
    ])->tag('controller.service_arguments');

    $services->set(ChatSessionPruner::class)->args([
        service(ChatSessionRepository::class),
        service(ClockInterface::class),
        service(LoggerInterface::class),
        param('guarded_chat.retention.days'),
        param('guarded_chat.retention.log_level'),
    ]);
    $services->set(PruneChatSessionsMessageHandler::class)->args([service(ChatSessionPruner::class)])->autoconfigure();
    $services->set(PruneChatSessionsCommand::class)->args([service(ChatSessionPruner::class)])->autoconfigure();
};
