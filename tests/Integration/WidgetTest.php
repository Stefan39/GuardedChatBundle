<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Integration;

use Stejac\GuardedChatBundle\Tests\Support\KernelTestCase;
use Stejac\GuardedChatBundle\Tests\Support\OffSwitch;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twig\Environment;

final class WidgetTest extends KernelTestCase
{
    public function testRendersTheWidgetWithRoutesAndTheArticle50Notice(): void
    {
        $html = $this->render(['locale' => 'en']);

        self::assertStringContainsString('data-controller="stejac--guarded-chat-bundle--widget"', $html);
        self::assertStringContainsString('data-stejac--guarded-chat-bundle--widget-session-url-value="/chat/session"', $html);
        self::assertStringContainsString('data-stejac--guarded-chat-bundle--widget-message-url-value="/chat/message"', $html);
        self::assertStringContainsString('You are chatting with an AI, not a person.', $html);
        self::assertStringContainsString('Hi, I am an AI assistant.', $html);
        self::assertStringContainsString('name="website"', $html);
    }

    public function testTextsFollowTheLocale(): void
    {
        $html = $this->render(['locale' => 'de']);

        self::assertStringContainsString('Sie chatten mit einer KI, nicht mit einem Menschen.', $html);
        self::assertStringContainsString('Hallo, ich bin ein KI-Assistent.', $html);
        self::assertStringContainsString('error-verification-value="Die Sicherheitsprüfung konnte nicht abgeschlossen werden.', $html);
    }

    public function testNoticeAndGreetingCanBeOverridden(): void
    {
        $html = $this->render(['locale' => 'en', 'notice' => 'Powered by AI.', 'greeting' => 'Welcome!']);

        self::assertStringContainsString('Powered by AI.', $html);
        self::assertStringContainsString('data-stejac--guarded-chat-bundle--widget-greeting-value="Welcome!"', $html);
        self::assertStringNotContainsString('You are chatting with an AI', $html);
    }

    public function testActionsBecomeTemplatesTheControllerClones(): void
    {
        $html = $this->render(['locale' => 'en', 'actions' => [['type' => 'contact', 'label' => 'Contact us', 'url' => '/contact']]]);

        self::assertStringContainsString('data-action-type="contact"', $html);
        self::assertStringContainsString('<a class="guarded-chat__action" href="/contact">Contact us</a>', $html);
    }

    public function testPassesTheStaticVerificationTokenThrough(): void
    {
        $html = $this->render(['locale' => 'en', 'verification_token' => 'tok-1']);

        self::assertStringContainsString('verification-token-value="tok-1"', $html);
    }

    public function testEscapesValuesPassedIn(): void
    {
        $html = $this->render(['locale' => 'en', 'greeting' => '"><script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testTheRootKeepsItsDefaultMarkupWithoutOverrides(): void
    {
        $html = $this->render(['locale' => 'en']);

        self::assertStringContainsString('<div class="guarded-chat"'."\n", $html);
        self::assertStringNotContainsString('user-class-value', $html);
        self::assertStringNotContainsString('assistant-class-value', $html);
    }

    public function testRootAttributesAreMergedOverTheDefaultClass(): void
    {
        $html = $this->render(['locale' => 'en', 'root_attributes' => [
            'class' => 'guarded-chat brand',
            'style' => '--guarded-chat-accent: #0f766e',
            'data-turbo-permanent' => true,
            'hidden' => false,
            'title' => null,
        ]]);

        self::assertStringContainsString('<div class="guarded-chat brand" style="--guarded-chat-accent: #0f766e" data-turbo-permanent'."\n", $html);
        self::assertStringNotContainsString('hidden', substr($html, 0, (int) strpos($html, '>')));
        self::assertStringNotContainsString('title=', $html);
    }

    public function testRootAttributesAreEscaped(): void
    {
        $html = $this->render(['locale' => 'en', 'root_attributes' => ['data-x' => '"><script>alert(1)</script>', 'onclick x' => 'y']]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onclick x=', $html);
    }

    public function testMessageClassesBecomeControllerValues(): void
    {
        $html = $this->render(['locale' => 'en', 'user_message_class' => 'bubble bubble--me', 'assistant_message_class' => 'bubble']);

        self::assertStringContainsString('data-stejac--guarded-chat-bundle--widget-user-class-value="bubble bubble--me"', $html);
        self::assertStringContainsString('data-stejac--guarded-chat-bundle--widget-assistant-class-value="bubble"', $html);
    }

    public function testAnExtendingTemplateCanSetRootAttributesAndMessageClasses(): void
    {
        $this->boot();

        $html = $this->renderString(<<<'TWIG'
            {% extends '@GuardedChat/widget.html.twig' %}
            {% set locale = 'en' %}
            {% set root_attributes = {class: 'guarded-chat brand'} %}
            {% set user_message_class = 'me' %}
            {% set assistant_message_class = 'bot' %}
            TWIG);

        self::assertStringContainsString('<div class="guarded-chat brand"', $html);
        self::assertStringContainsString('user-class-value="me"', $html);
        self::assertStringContainsString('assistant-class-value="bot"', $html);
    }

    public function testChatEnabledIsTrueWhileBothStagesAreOn(): void
    {
        $this->boot();

        self::assertSame('on', $this->renderString("{{ chat_enabled() ? 'on' : 'off' }}"));
    }

    public function testChatEnabledFollowsTheConfigurationFlag(): void
    {
        $this->boot(['enabled' => false]);

        self::assertSame('off', $this->renderString("{{ chat_enabled() ? 'on' : 'off' }}"));
    }

    public function testChatEnabledFollowsTheRuntimeSwitch(): void
    {
        $this->boot(['runtime_switch' => 'test.off_switch'], static function (ContainerBuilder $builder): void {
            $builder->register('test.off_switch', OffSwitch::class);
        });

        self::assertSame('off', $this->renderString("{{ chat_enabled() ? 'on' : 'off' }}"));
    }

    public function testBundleAssetsAreServedByAssetMapper(): void
    {
        $this->boot();
        $mapper = $this->service(AssetMapperInterface::class);

        foreach (['controller.js', 'state.js', 'link_parser.js', 'plain_text.js', 'widget.css'] as $file) {
            self::assertNotNull($mapper->getAsset('@stejac/guarded-chat-bundle/dist/'.$file), $file);
        }
    }

    private function renderString(string $template): string
    {
        $twig = $this->service(Environment::class);

        return $twig->createTemplate($template)->render();
    }

    /** @param array<string, mixed> $context */
    private function render(array $context): string
    {
        $this->boot();
        $twig = $this->service(Environment::class);

        return $twig->render('@GuardedChat/widget.html.twig', $context);
    }
}
