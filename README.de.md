# GuardedChatBundle

> Leitplanken für öffentliche KI-Chats in Symfony-Anwendungen: signierte Sessions, versionierte Prompts, Injection-Vorfilter, Kill-Switch, Rate-Limits, Budget-Stopp und Löschfristen. Aufgesetzt auf Symfony AI, mit einsatzbereitem Chat-Widget.

[![CI](https://github.com/Stefan39/GuardedChatBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/GuardedChatBundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/stejac/guarded-chat-bundle.svg)](https://packagist.org/packages/stejac/guarded-chat-bundle)
[![codecov](https://codecov.io/gh/Stefan39/GuardedChatBundle/branch/main/graph/badge.svg)](https://codecov.io/gh/Stefan39/GuardedChatBundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

[English version](README.md)

<p align="center">
  <img src="docs/demo/demo.gif" alt="Das Chat-Widget streamt eine Antwort, blockt einen Prompt-Injection-Versuch und beantwortet eine Nachrichtenflut mit einem Rate-Limit-Hinweis" width="360">
</p>

## Warum nicht direkt `symfony/ai`?

[Symfony AI](https://github.com/symfony/ai) spricht mit dem Modell: Plattformen, Agents, Tools, Streaming. Alles rund um einen *öffentlichen, anonymen* Chat-Endpunkt lässt es bewusst offen, und genau das tut weh, sobald eine Seite live geht:

| Problem bei einem öffentlichen Chat | Symfony AI allein | Dieses Bundle |
|---|---|---|
| Besucher schreiben „ignore previous instructions“ | Sache der App | Vorfilter als Input-Processor, das Modell wird gar nicht aufgerufen |
| Ein Bot oder ein gelangweilter Besucher leert das Token-Budget | Sache der App | Rate-Limits pro Client, Nachrichtenlimit je Session, Budget-Stopp |
| Wer darf eine Unterhaltung fortsetzen? | Sache der App | signierte Session-Tokens, Lebensdauer und Leerlauf-Timeout |
| Prompt-Wortlaut ohne Deploy korrigieren | Code-Konstante | versionierte Prompts in der Datenbank, genau einer aktiv |
| Den Chat bei einem Vorfall abschalten | Sache der App | zweistufiger Kill-Switch (Konfigurationsflag plus Laufzeitschalter) |
| Datenschutz: Wie lange liegen Gesprächsverläufe? | Sache der App | Löschfrist mit zeitgesteuertem Prune |
| Besucher darauf hinweisen, dass sie mit einer KI sprechen (Art. 50 KI-VO) | Sache der App | Hinweis im Widget und erzwungene Prompt-Anweisung |

Das Bundle nutzt Symfony AI als Fundament und tritt nicht dagegen an: Genau eine interne Klasse berührt es (`Internal\AiStreamAdapter`), eine Änderung in der 0.x-Reihe zieht also keine Welle durch den Rest.

## Schnellstart

```bash
composer require stejac/guarded-chat-bundle
```

```yaml
# config/packages/guarded_chat.yaml
guarded_chat:
    platform: ai.platform.generic.litellm      # jeder Symfony-AI-Plattformdienst
    model: my-model
    prompt:
        default: 'Du bist der Assistent von Acme Bikes. Beantworte nur Fragen zu Fahrrädern und unserem Shop.'
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

Mehr ist für die Einbindung nicht nötig. Das Widget braucht AssetMapper mit dem Symfony-UX-StimulusBundle (`composer require symfony/asset-mapper symfony/stimulus-bundle`); Flex registriert den Controller automatisch. Das Bundle registriert sein Doctrine-Mapping, liefert aber **keine Migrationen**: `bin/console doctrine:migrations:diff` ausführen und das Ergebnis prüfen.

Fehlt der Eintrag `@stejac/guarded-chat-bundle` in `assets/controllers.json` nach einem Composer-Update, kommst du von einer Version ohne das Schlüsselwort `symfony-ux` (vor v1.1.1), und Flex hat den Eintrag entfernt. Trage ihn von Hand wieder ein oder führe `composer update stejac/guarded-chat-bundle` aus, sobald das Release mit dem Schlüsselwort installiert ist; danach behält Flex den Eintrag.

Das Recipe für [Symfony Flex](https://github.com/symfony/flex) ist eingereicht (symfony/recipes-contrib#2093), aber noch nicht gemergt. Bis dahin legst du die beiden Dateien aus dem Schnellstart oben selbst an; Flex trägt das Bundle trotzdem in `config/bundles.php` ein. Die Plattform selbst konfigurierst du wie in der [Symfony-AI-Dokumentation](https://github.com/symfony/ai) beschrieben, zum Beispiel mit der Generic-Bridge für ein OpenAI-kompatibles Gateway wie LiteLLM:

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

## Funktionen

- **Signierte Session-Tokens** und Limits je Session (Lebensdauer, Leerlauf, Nachrichtenzahl, Verlaufsfenster)
- **Versionierte System-Prompts** in der Datenbank, mit konfiguriertem Fallback und Anweisungen, die für jede Version gelten (KI-Hinweis, Anrede, Ausgabeformat)
- **Vorfilter** für offensichtliche Prompt-Injection-Formulierungen, als Symfony-AI-Input-Processor
- **Zweistufiger Kill-Switch**: ein hartes `enabled`-Flag plus ein Laufzeitschalter ohne Deploy
- **Rate-Limits** pro Client (Sessionstart, Nachrichten pro Minute, Nachrichten pro Tag) und ein **Budget-Stopp**, der ein erschöpftes Gateway-Budget erkennt
- **Hook für menschliche Verifikation** plus Honeypot-Feld
- **Wissensbasis** mit austauschbarem Retrieval (PostgreSQL-Volltext mitgeliefert)
- **Löschfristen**: Sessions und ihre Nachrichten werden nach einer einstellbaren Zahl von Tagen gelöscht
- **Streaming** der Antworten als Server-Sent Events, mit konfigurierbaren **Aktions-Markern** (z. B. wird `[[contact]]` zu einem `action`-Event und einem Button)
- **Widget**: ein Stimulus-Controller und ein überschreibbares Twig-Template, kein CSS-Framework nötig

## Das Widget

`@GuardedChat/widget.html.twig` zeigt einen Launcher-Button und ein Chat-Panel. Die Texte liegen auf Englisch und Deutsch in der Übersetzungsdomäne `guarded_chat`, darunter der KI-Hinweis im Panel-Kopf und die Begrüßung, die gegenüber dem Besucher offenlegt, dass er mit einer KI spricht.

```twig
{{ include('@GuardedChat/widget.html.twig', {
    actions: [{type: 'contact', label: 'Kontakt aufnehmen', url: path('contact')}],
    notice: 'Antworten werden von einer KI erzeugt und können Fehler enthalten.',
}) }}
```

| Variable | Bedeutung |
|---|---|
| `locale` | Sprache, die bei jeder Anfrage mitgeschickt wird, Standard: Request-Locale |
| `session_url`, `message_url` | Endpunkte, Standard: die Routen des Bundles |
| `greeting` | Erste Assistenten-Nachricht (zugleich KI-Hinweis), `''` blendet sie aus |
| `notice` | Text unter dem Panel-Titel |
| `actions` | Einträge `{type, label, url}`; unter Antworten mit dieser Aktion erscheint ein Button |
| `verification_token` | Statisches Token für den Hook zur menschlichen Verifikation |
| `max_message_length` | `maxlength` des Eingabefelds, Standard 500 |
| `root_attributes` | Zusätzliche Attribute des Wurzel-Elements, über `{class: 'guarded-chat'}` gemergt; `true` setzt ein Attribut ohne Wert, `false` und `null` lassen es weg |
| `user_message_class`, `assistant_message_class` | Klassen der Sprechblasen |

**Nur bei aktivem Chat.** `chat_enabled()` prüft dieselben zwei Stufen wie der Session-Endpunkt (den Schalter `enabled` und deinen Runtime-Switch):

```twig
{% if chat_enabled() %}
    {{ include('@GuardedChat/widget.html.twig') }}
{% endif %}
```

**Gestaltung.** Das Standard-Aussehen ist ein kleines Stylesheet, gesteuert über CSS-Variablen; Flex lädt es, sonst `{{ asset('@stejac/guarded-chat-bundle/dist/widget.css') }}` einbinden:

```css
.guarded-chat { --guarded-chat-accent: #0f766e; --guarded-chat-radius: 0.25rem; }
```

**Eigenes Markup.** Das Template erweitern und einen Block überschreiben (`launcher`, `header`, `notice`, `messages`, `actions`, `form`) oder die Datei nach `templates/bundles/GuardedChatBundle/widget.html.twig` kopieren. Der Controller verlässt sich nur auf die Attribute `data-…-target` und `data-action`, Tailwind-Klassen oder jedes andere Design funktionieren also. Die Variablen oben lassen sich auch im erweiternden Template setzen:

```twig
{% extends '@GuardedChat/widget.html.twig' %}

{% set root_attributes = {class: 'guarded-chat', style: '--guarded-chat-accent: #0f766e'} %}
{% set user_message_class = 'rounded-lg bg-teal-600 px-3 py-2 text-white self-end' %}
{% set assistant_message_class = 'rounded-lg bg-gray-100 px-3 py-2' %}

{% block launcher %}…{% endblock %}
```

**Menschliche Verifikation.** Das Widget löst vor dem Sessionstart ein `verify`-Event aus. Ein Listener liefert ein Token, das als `verificationToken` an `POST /session` geht und von deinem `HumanVerifierInterface` geprüft wird. Beispiel mit Cloudflare Turnstile (das Bundle hängt nicht davon ab):

```js
document.addEventListener('stejac--guarded-chat-bundle--widget:verify', (event) => {
    event.detail.provide(new Promise((resolve, reject) => turnstile.render('#captcha', {
        sitekey: '…',
        callback: resolve,
        'error-callback': () => reject(Object.assign(new Error('Turnstile failed'), {
            userMessage: 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte laden Sie die Seite neu.',
        })),
    })));
});
```

Wird das Promise abgelehnt, zeigt das Widget den Grund an, wenn er ein String ist oder ein `userMessage` trägt, sonst den übersetzten Text `widget.error_verification`. Die Meldung eines einfachen `Error` erscheint nie im Widget, sie bleibt in der Konsole.

## Konfiguration

```yaml
guarded_chat:
    enabled: '%env(bool:GUARDED_CHAT_ENABLED)%'   # Stufe 1 des Kill-Switch
    platform: ai.platform.generic.litellm
    model: my-model
    max_output_tokens: 300
    prompt:
        default: '…'                    # Fallback, bis eine Prompt-Version gespeichert ist
        pinned_instructions:
            - 'Antworte in reinem Text ohne Markdown.'
    actions:
        contact:
            marker: '[[contact]]'
            instruction: 'Wenn der Besucher Kontakt aufnehmen sollte, gib [[contact]] als letztes Token aus.'
    session: { lifetime: 7200, idle_timeout: 1800, max_messages: 20, max_history: 6 }
    rate_limits:
        session: { limit: 5, interval: '1 hour' }
        message: { limit: 8, interval: '1 minute' }
        message_daily: { limit: 50, interval: '1 day' }
    retention: { days: 90, log_level: info }
```

Existiert der unter `platform` genannte Service nicht, schaltet sich der Chat ab und es wird eine Meldung geloggt, statt dass der Container nicht kompiliert.

## Endpunkte

| Route | Zweck |
|---|---|
| `POST /chat/session` | Startet eine Session. Body: `{"verificationToken": "", "website": "", "locale": "de"}` (`website` ist der Honeypot und muss leer bleiben). |
| `POST /chat/message` | Sendet `{"message": "...", "locale": "de"}` und streamt die Antwort als `text/event-stream` mit den Events `text` und `action`. |

Fehler kommen als JSON mit stabilem `error`-Code und übersetzter `message`: `rate_limited`, `message_limit_reached`, `budget_exhausted`, `gateway_unavailable`, `verification_failed`, `invalid_token`, `session_expired`, `invalid_request`.

## Erweiterungspunkte

Jeder ist ein Service, auf den du den passenden Konfigurationsschlüssel zeigen lässt; die Standardwerte sind neutral.

| Schlüssel | Interface | Standard |
|---|---|---|
| `runtime_switch` | `ChatRuntimeSwitchInterface` | immer an |
| `human_verifier` | `HumanVerifierInterface` | lässt jeden durch |
| `client_identifier_resolver` | `ClientIdentifierResolverInterface` | `Request::getClientIp()` |
| `budget_exhaustion_detector` | `BudgetExhaustionDetectorInterface` | HTTP 429 bedeutet Budget erschöpft |
| `knowledge.retriever` | `KnowledgeRetrieverInterface` | keiner (`NullRetriever`) |

## Wissensbasis

Antworten können auf handgepflegten Einträgen beruhen (`chat_knowledge_snippet`: Titel, optionale typische Frage, Inhalt, Priorität, Quell-URL). Als `admin` angelegte Einträge sind sofort aktiv; per `api` vorgeschlagene bleiben ruhend, bis eine Person sie freigibt, damit ein Besucher nicht steuern kann, was der Assistent weiß.

```yaml
guarded_chat:
    knowledge:
        retriever: postgres_full_text   # Standard: keiner (das Bundle läuft dann auf jeder Datenbank)
        text_search_config: german       # PostgreSQL-Textsuchkonfiguration, Standard "german"
        max_snippets: 4                  # pro Antwort; begrenzt die Prompt-Größe
        subject: 'Acme Corp'             # "Gesicherte Informationen über Acme Corp." (optional)
```

`postgres_full_text` braucht PostgreSQL. Für jeden anderen Speicher `KnowledgeRetrieverInterface` implementieren (`retrieve(string $question, int $limit): list<KnowledgeSnippetInterface>`) und die Service-ID bei `retriever` eintragen. Die IDs der Einträge, die in eine Antwort eingeflossen sind, stehen in `chat_message.context_snippet_ids`. Der Kopftext ist in der Domäne `guarded_chat` übersetzbar (`knowledge.*`).

## Löschfristen

`bin/console guarded-chat:prune` löscht Sessions, die älter als `retention.days` sind (Standard 90). Sind `symfony/scheduler` und `dragonmantank/cron-expression` installiert, registriert `retention.schedule.enabled: true` einen wiederkehrenden Prune. Jeder Lauf schreibt einen Log-Eintrag mit der Zahl der gelöschten Sessions. `retention.log_level` (Standard `info`) nimmt jedes PSR-3-Level, damit auch ein Produktiv-Log, das erst ab `warning` speichert, den Lauf festhält.

## Voraussetzungen

PHP 8.3+, Symfony 7.4 oder 8, Doctrine ORM 3, `symfony/ai-bundle` 0.14 (wird mitinstalliert). Das Widget braucht zusätzlich `symfony/twig-bundle`, `symfony/asset-mapper` und `symfony/stimulus-bundle`.

## Entwicklung

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan, höchste Stufe
composer cs        # php-cs-fixer (Trockenlauf)
node --test "tests/js/*.test.mjs"   # Widget-Helfer
```

Die PostgreSQL-Tests brauchen `GUARDED_CHAT_TEST_DATABASE_URL`, siehe [CONTRIBUTING.md](CONTRIBUTING.md).

## Lizenz

MIT
