# GuardedChatBundle

> Guardrails for public AI chats in Symfony apps: signed sessions, prompt versioning, injection prefilter, kill switch, rate limits, budget stop, retention. Built on Symfony AI, with a drop-in chat widget.

[![CI](https://github.com/Stefan39/GuardedChatBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/GuardedChatBundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/stejac/guarded-chat-bundle.svg)](https://packagist.org/packages/stejac/guarded-chat-bundle)
[![codecov](https://codecov.io/gh/Stefan39/GuardedChatBundle/branch/main/graph/badge.svg)](https://codecov.io/gh/Stefan39/GuardedChatBundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

[Deutsche Version](README.de.md)

<p align="center">
  <img src="docs/demo/demo.gif" alt="The chat widget streams an answer, blocks a prompt-injection attempt and answers a flood of messages with a rate-limit notice" width="360">
</p>

## Why not just use `symfony/ai`?

[Symfony AI](https://github.com/symfony/ai) talks to the model: platforms, agents, tools, streaming. It deliberately leaves open everything around a *public, anonymous* chat endpoint, and that is the part that hurts when a site goes live:

| Problem on a public chat | Symfony AI alone | This bundle |
|---|---|---|
| Visitors paste "ignore previous instructions" | up to you | prefilter as an input processor, the model is never called |
| A bot or a bored visitor drains your token budget | up to you | per-client rate limits, session message cap, budget stop |
| Who is allowed to continue a conversation? | up to you | signed session tokens, lifetime and idle timeout |
| Fix the wording of the system prompt without a deploy | code constant | versioned prompts in the database, one active at a time |
| Switch the chat off during an incident | up to you | two-stage kill switch (config flag plus runtime switch) |
| Privacy: how long do you keep transcripts? | up to you | retention with a scheduled prune |
| Telling visitors they talk to an AI (Art. 50 EU AI Act) | up to you | disclosure in the widget and an enforced prompt instruction |

The bundle uses Symfony AI as its foundation, not as a competitor: exactly one internal class touches it (`Internal\AiStreamAdapter`), so a 0.x change there does not ripple through the rest.

## Quick start

```bash
composer require stejac/guarded-chat-bundle
```

```yaml
# config/packages/guarded_chat.yaml
guarded_chat:
    platform: ai.platform.generic.litellm      # any Symfony AI platform service
    model: my-model
    prompt:
        default: 'You are the assistant of Acme Bikes. Answer only questions about bikes and our shop.'
```

```yaml
# config/routes/guarded_chat.yaml
guarded_chat:
    resource: '@GuardedChatBundle/config/routes.php'
    prefix: /chat
```

```twig
{# templates/base.html.twig #}
{{ include('@GuardedChat/widget.html.twig') }}
```

That is the whole integration: the widget needs AssetMapper with Symfony UX StimulusBundle (`composer require symfony/asset-mapper symfony/stimulus-bundle`); Flex registers its controller automatically. The bundle registers its Doctrine mapping but ships **no migrations**: run `bin/console doctrine:migrations:diff` and review the result.

With [Symfony Flex](https://github.com/symfony/flex) the recipe adds the config files and an `GUARDED_CHAT_ENABLED` variable for you. For the platform itself see the [Symfony AI documentation](https://github.com/symfony/ai), for example the generic bridge for an OpenAI-compatible gateway such as LiteLLM:

```yaml
# config/packages/ai.yaml
ai:
    platform:
        generic:
            litellm:
                base_url: '%env(LITELLM_URL)%'
                api_key: '%env(LITELLM_KEY)%'
                completions_path: /chat/completions
```

## Features

- **Signed session tokens** and per-session limits (lifetime, idle timeout, message count, history window)
- **Versioned system prompts** in the database, with a configured fallback and instructions that hold for every version (AI disclosure, form of address, output format)
- **Prefilter** for obvious prompt-injection phrases, as a Symfony AI input processor
- **Two-stage kill switch**: a hard `enabled` flag plus a runtime switch you can flip without a deploy
- **Rate limits** per client (session start, messages per minute, messages per day) and a **budget stop** that recognises an exhausted gateway budget
- **Human verification** hook plus a honeypot field
- **Knowledge base** with pluggable retrieval (PostgreSQL full text out of the box)
- **Retention**: sessions and their messages are deleted after a configurable number of days
- **Streaming** answers as server-sent events, with configurable **action markers** (e.g. `[[contact]]` becomes an `action` event and a button)
- **Widget**: a Stimulus controller plus an overridable Twig template, no CSS framework required

## The widget

`@GuardedChat/widget.html.twig` renders a launcher button and a chat panel. Texts come in English and German from the `guarded_chat` translation domain, including the AI notice in the panel header and the greeting that discloses the AI to the visitor.

```twig
{{ include('@GuardedChat/widget.html.twig', {
    actions: [{type: 'contact', label: 'Contact us', url: path('contact')}],
    notice: 'Answers are generated by an AI and may contain errors.',
}) }}
```

| Variable | Meaning |
|---|---|
| `locale` | Locale sent with every request, default: request locale |
| `session_url`, `message_url` | Endpoints, default: the bundle's routes |
| `greeting` | First assistant message (also the AI disclosure), `''` hides it |
| `notice` | Text under the panel title |
| `actions` | `{type, label, url}` entries; a button is shown under answers that carry that action |
| `verification_token` | Static token for the human verification hook |
| `max_message_length` | Input `maxlength`, default 500 |

**Styling.** The default look is a small stylesheet driven by CSS custom properties; Flex loads it, otherwise add `{{ asset('@stejac/guarded-chat-bundle/dist/widget.css') }}`:

```css
.guarded-chat { --guarded-chat-accent: #0f766e; --guarded-chat-radius: 0.25rem; }
```

**Your own markup.** Extend the template and override a block (`launcher`, `header`, `notice`, `messages`, `actions`, `form`), or copy it to `templates/bundles/GuardedChatBundle/widget.html.twig`. The controller relies only on the `data-…-target` and `data-action` attributes, so Tailwind classes or any other design work. The bubble classes are values on the controller (`user-class`, `assistant-class`).

**Human verification.** The widget dispatches a `verify` event before it starts a session. A listener hands over a token, which is sent as `verificationToken` to `POST /session` and checked by your `HumanVerifierInterface`. Example with Cloudflare Turnstile (the bundle has no dependency on it):

```js
document.addEventListener('stejac--guarded-chat-bundle--widget:verify', (event) => {
    event.detail.provide(new Promise((resolve) => turnstile.render('#captcha', {
        sitekey: '…', callback: resolve,
    })));
});
```

## Configuration

```yaml
guarded_chat:
    enabled: '%env(bool:GUARDED_CHAT_ENABLED)%'   # stage 1 of the kill switch
    platform: ai.platform.generic.litellm
    model: my-model
    max_output_tokens: 300
    prompt:
        default: '…'                    # fallback until a prompt version is stored
        pinned_instructions:
            - 'Answer in plain text without Markdown.'
    actions:
        contact:
            marker: '[[contact]]'
            instruction: 'When the visitor should get in touch, output [[contact]] as the final token.'
    session: { lifetime: 7200, idle_timeout: 1800, max_messages: 20, max_history: 6 }
    rate_limits:
        session: { limit: 5, interval: '1 hour' }
        message: { limit: 8, interval: '1 minute' }
        message_daily: { limit: 50, interval: '1 day' }
    retention: { days: 90 }
```

## Endpoints

| Route | Purpose |
|---|---|
| `POST /chat/session` | Starts a session. Body: `{"verificationToken": "", "website": "", "locale": "en"}` (`website` is the honeypot and must stay empty). |
| `POST /chat/message` | Sends `{"message": "...", "locale": "en"}` and streams the answer as `text/event-stream` with `text` and `action` events. |

Errors are JSON with a stable `error` code and a translated `message`: `rate_limited`, `message_limit_reached`, `budget_exhausted`, `gateway_unavailable`, `verification_failed`, `invalid_token`, `session_expired`, `invalid_request`.

## Extension points

Each is a service you point the matching configuration key at; the defaults are neutral.

| Key | Interface | Default |
|---|---|---|
| `runtime_switch` | `ChatRuntimeSwitchInterface` | always on |
| `human_verifier` | `HumanVerifierInterface` | lets everyone through |
| `client_identifier_resolver` | `ClientIdentifierResolverInterface` | `Request::getClientIp()` |
| `budget_exhaustion_detector` | `BudgetExhaustionDetectorInterface` | HTTP 429 means budget exhausted |
| `knowledge.retriever` | `KnowledgeRetrieverInterface` | none (`NullRetriever`) |

## Knowledge base

Answers can draw on hand-curated snippets (`chat_knowledge_snippet`: title, optional typical question, content, priority, source URL). Snippets created as `admin` are active at once; snippets suggested as `api` stay dormant until a person activates them, so a visitor cannot steer what the assistant knows.

```yaml
guarded_chat:
    knowledge:
        retriever: postgres_full_text   # default: none (the bundle then runs on any database)
        text_search_config: english      # PostgreSQL text search configuration, default "german"
        max_snippets: 4                  # per answer; bounds the prompt size
        subject: 'Acme Corp'             # "Verified information about Acme Corp." (optional)
```

`postgres_full_text` needs PostgreSQL. For any other store, implement `KnowledgeRetrieverInterface` (`retrieve(string $question, int $limit): list<KnowledgeSnippetInterface>`) and put its service id into `retriever`. The ids of the snippets that fed an answer are stored with it in `chat_message.context_snippet_ids`. The header wording is translatable in the `guarded_chat` domain (`knowledge.*`).

## Retention

`bin/console guarded-chat:prune` deletes sessions older than `retention.days` (default 90). With `symfony/scheduler` and `dragonmantank/cron-expression` installed, `retention.schedule.enabled: true` registers a recurring prune.

## Requirements

PHP 8.3+, Symfony 7.4 or 8, Doctrine ORM 3, `symfony/ai-bundle` 0.14 (installed with the bundle). The widget additionally needs `symfony/twig-bundle`, `symfony/asset-mapper` and `symfony/stimulus-bundle`.

## Development

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan, max level
composer cs        # php-cs-fixer (dry run)
node --test "tests/js/*.test.mjs"   # widget helpers
```

The PostgreSQL tests need `GUARDED_CHAT_TEST_DATABASE_URL`, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
