# GuardedChatBundle

Guardrails for public AI chats in Symfony apps: prompt versioning, injection prefilter, kill switch, budget and rate limits, signed sessions, retention. Built on Symfony AI.

> **Status: v0.1 (core and knowledge base).** The chat widget and the full documentation (English and German, with a demo) follow in later releases. The API may still change before 1.0.

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

## Development

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan, max level
composer cs        # php-cs-fixer (dry run)
```

The PostgreSQL tests need `GUARDED_CHAT_TEST_DATABASE_URL`, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
