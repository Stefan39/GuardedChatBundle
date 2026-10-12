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
4. Keep PRs focused — one change per PR. Conventional Commits are appreciated (`fix: …`, `feat: …`).

## Code style

- `declare(strict_types=1);` in every PHP file.
- PSR-12 + Symfony rules via php-cs-fixer.
- No generic `\Exception` / `\RuntimeException` — use a specific exception class.

## Response times

Issues are triaged within a few working days. Maintainer: [@Stefan39](https://github.com/Stefan39).
