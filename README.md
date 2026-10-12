# GuardedChatBundle

Guardrails for public AI chats in Symfony apps: prompt versioning, injection prefilter, kill switch, budget and rate limits, signed sessions, retention. Built on Symfony AI.

> **Status: v0.1 (core).** The knowledge base, the chat widget and the full documentation (English and German, with a demo) follow in later releases. The API may still change before 1.0.

## What it adds on top of Symfony AI

Symfony AI talks to the model. This bundle is the layer around a *public, anonymous* chat endpoint:

- **Signed session tokens** and per-session limits (lifetime, idle timeout, message count, history window)
- **Versioned system prompts** in the database, with a configured fallback and instructions that hold for every version (AI disclosure, form of address, output format)
- **Prefilter** for obvious prompt-injection phrases, as a Symfony AI input processor: a blocked message never reaches the model
- **Two-stage kill switch**: a hard `enabled` flag plus a runtime switch you can flip without a deploy
- **Rate limits** per client (session start, messages per minute, messages per day) and a **budget stop** that recognises an exhausted gateway budget
- **Human verification** hook plus a honeypot field
- **Retention**: sessions and their messages are deleted after a configurable number of days
- **Streaming** answers as server-sent events, with configurable **action markers** (e.g. `[[contact]]` becomes an `action` event)

## Installation

```bash
composer require stejac/guarded-chat-bundle
```

Register `Stejac\GuardedChatBundle\GuardedChatBundle` and configure a Symfony AI platform, for example the generic bridge for an OpenAI-compatible gateway such as LiteLLM:

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

```yaml
# config/packages/guarded_chat.yaml
guarded_chat:
    enabled: '%env(bool:CHAT_ENABLED)%'
    platform: ai.platform.generic.litellm
    model: my-model
    prompt:
        default: 'You are the assistant of <your site>. Answer only questions about <your topic>.'
        pinned_instructions:
            - 'Answer in plain text without Markdown.'
    actions:
        contact:
            marker: '[[contact]]'
            instruction: 'When the visitor should get in touch, output [[contact]] as the final token.'
```

```yaml
# config/routes/guarded_chat.yaml
guarded_chat:
    resource: '@GuardedChatBundle/config/routes.php'
    prefix: /chat
```

The bundle registers its Doctrine mapping but ships **no migrations**: run `bin/console doctrine:migrations:diff` and review the result. The tables are `chat_session`, `chat_message` and `chat_prompt`.

## Endpoints

| Route | Purpose |
|---|---|
| `POST /chat/session` | Starts a session. Body: `{"verificationToken": "", "website": "", "locale": "en"}` (`website` is the honeypot and must stay empty). |
| `POST /chat/message` | Sends `{"message": "...", "locale": "en"}` and streams the answer as `text/event-stream` with `text` and `action` events. |

Errors are JSON with a stable `error` code: `rate_limited`, `message_limit_reached`, `budget_exhausted`, `gateway_unavailable`, `verification_failed`, `invalid_token`, `session_expired`, `invalid_request`.

## Extension points

Each is a service you point the matching configuration key at; the defaults are neutral.

| Key | Interface | Default |
|---|---|---|
| `runtime_switch` | `ChatRuntimeSwitchInterface` | always on |
| `human_verifier` | `HumanVerifierInterface` | lets everyone through |
| `client_identifier_resolver` | `ClientIdentifierResolverInterface` | `Request::getClientIp()` |
| `budget_exhaustion_detector` | `BudgetExhaustionDetectorInterface` | HTTP 429 means budget exhausted |

## Retention

`bin/console guarded-chat:prune` deletes sessions older than `retention.days` (default 90). With `symfony/scheduler` and `dragonmantank/cron-expression` installed, `retention.schedule.enabled: true` registers a recurring prune.

## Development

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan, max level
composer cs        # php-cs-fixer (dry run)
```

## License

MIT
