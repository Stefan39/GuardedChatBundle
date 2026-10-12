# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Bundle skeleton, chat session tokens, prompt versions, guards, streaming pipeline on Symfony AI and retention (v0.1 core).

- Knowledge base: `ChatKnowledgeSnippet` with unchanged table, `KnowledgeRetrieverInterface`, `PostgresFullTextRetriever` (configurable text search configuration) and a `NullRetriever` default, translatable `ChatKnowledgeFormatter`, snippet ids recorded with each answer.

- Chat widget: Stimulus controller (`assets/`, served by AssetMapper), overridable `@GuardedChat/widget.html.twig` with blocks, default stylesheet driven by CSS custom properties, English and German texts including the Art. 50 AI notice, a `verify` event as the hook for human verification, configurable action buttons.
- README in English and German with demo GIF, a section on how the bundle relates to Symfony AI, and the Flex recipe sources under `docs/recipe/`.

[Unreleased]: https://github.com/Stefan39/GuardedChatBundle/commits/main
