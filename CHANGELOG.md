# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- A missing Symfony AI platform service no longer breaks the container: the chat is switched off and the reason is logged. Fresh installs with the recipe boot before a platform is configured.

## [1.1.1] - 2026-10-25

### Fixed
- `composer.json` now carries the `symfony-ux` keyword. Without it Symfony Flex dropped the bundle's entry from `assets/controllers.json` on every `composer update`, `require` or `remove`, so the chat widget was no longer loaded. After upgrading from an earlier version, check that the entry is still there and re-add it if needed (see README).

## [1.1.0] - 2026-10-22

### Added
- `retention.log_level` sets the PSR-3 level of the log entry each prune run writes (default `info`), so production logs that keep only `warning` and above still record it.
- `chat_enabled()` Twig function with the same two-stage check as the session endpoint, registered when Twig is installed.
- A `verify` listener can reject its token promise with a string or an error carrying `userMessage`; the widget shows that text. Without one it shows the new `widget.error_verification` text instead of the generic error.
- Widget template variables `root_attributes`, `user_message_class` and `assistant_message_class`, also settable in a template that extends `@GuardedChat/widget.html.twig`.

## [1.0.2] - 2026-10-12

### Fixed
- `%env(...)%` placeholders for the `enabled` flag (as used in the README and the Flex recipe) made the container fail with "Invalid type for path guarded_chat.enabled". The rate limiters are now derived from the raw configuration instead of a second pass through the full config tree.
- `symfony/translation` and `symfony/yaml` are required instead of only suggested through dev dependencies: the bundle ships YAML translations and needs a translator, so a fresh skeleton app failed on `cache:clear` right after `composer require`.

## [1.0.1] - 2026-10-12

### Fixed
- Require `symfony/ai-bundle`. The `ai:` platform configuration that the README and the Flex recipe of `symfony/ai-generic-platform` rely on is loaded by that bundle; without it `cache:clear` failed right after `composer require` in an app that did not have it yet.

## [1.0.0] - 2026-10-12

### Added
- Bundle skeleton, chat session tokens, prompt versions, guards, streaming pipeline on Symfony AI and retention (v0.1 core).
- Knowledge base: `ChatKnowledgeSnippet` with unchanged table, `KnowledgeRetrieverInterface`, `PostgresFullTextRetriever` (configurable text search configuration) and a `NullRetriever` default, translatable `ChatKnowledgeFormatter`, snippet ids recorded with each answer.
- Chat widget: Stimulus controller (`assets/`, served by AssetMapper), overridable `@GuardedChat/widget.html.twig` with blocks, default stylesheet driven by CSS custom properties, English and German texts including the Art. 50 AI notice, a `verify` event as the hook for human verification, configurable action buttons.
- README in English and German with demo GIF, a section on how the bundle relates to Symfony AI, and the Flex recipe sources under `docs/recipe/`.

[Unreleased]: https://github.com/Stefan39/GuardedChatBundle/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/Stefan39/GuardedChatBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/Stefan39/GuardedChatBundle/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/Stefan39/GuardedChatBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Stefan39/GuardedChatBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Stefan39/GuardedChatBundle/releases/tag/v1.0.0
