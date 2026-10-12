# Contributing

Thanks for taking the time! Issues and pull requests in English or German are welcome.

## Before you open an issue

- Search existing issues first.
- For bugs: version, PHP version, minimal reproduction, expected vs. actual behaviour.
- Security problems: **do not** open an issue — see [SECURITY.md](SECURITY.md).

## Pull requests

1. Fork, then branch from `main`.
2. Add a test that fails without your change.
3. Run the checks locally:
   ```bash
   composer test      # PHPUnit
   composer phpstan   # PHPStan max level
   composer cs        # php-cs-fixer (dry run)
   ```
   The PostgreSQL tests (full-text search, generated DDL) are skipped unless `GUARDED_CHAT_TEST_DATABASE_URL` points at a throw-away database; the public schema is dropped on every boot:
   ```bash
   docker run -d --name guarded-chat-pg -e POSTGRES_PASSWORD=test -e POSTGRES_DB=guarded_chat_test -p 54329:5432 postgres:16-alpine
   export GUARDED_CHAT_TEST_DATABASE_URL='postgresql://postgres:test@127.0.0.1:54329/guarded_chat_test?serverVersion=16&charset=utf8'
   ```
4. Keep PRs focused — one change per PR. Conventional Commits are appreciated (`fix: …`, `feat: …`).

## Code style

- `declare(strict_types=1);` in every PHP file.
- PSR-12 + Symfony rules via php-cs-fixer.
- No generic `\Exception` / `\RuntimeException` — use a specific exception class.

## Response times

Issues are triaged within a few working days. Maintainer: [@Stefan39](https://github.com/Stefan39).

## Install test

`tests/install/run.sh 7.4` (or `8.0`) installs the bundle from your checkout into a fresh Flex skeleton, applies the recipe from `docs/recipe/` and checks that `cache:clear` succeeds and the routes are registered. CI runs it for both Symfony lines. Run it before changing dependencies, the configuration tree or the recipe.
