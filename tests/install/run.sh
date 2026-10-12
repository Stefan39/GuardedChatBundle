#!/usr/bin/env bash
# Installs the bundle into a fresh Flex skeleton and checks that it boots, the way a new user would.
#
#   tests/install/run.sh 7.4   # or 8.0
#
# The bundle comes from this checkout (as version 1.0.99). The recipe is applied by hand from
# docs/recipe/ because a recipe in symfony/recipes-contrib is only available after it has been merged.
set -euo pipefail

symfony="${1:?usage: run.sh <symfony minor, e.g. 7.4>}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
recipe="$root/docs/recipe/stejac/guarded-chat-bundle/1.0"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cd "$work"

cat > composer.json <<JSON
{
    "name": "test/install",
    "type": "project",
    "minimum-stability": "stable",
    "repositories": [{"type": "path", "url": "$root", "options": {"symlink": false, "versions": {"stejac/guarded-chat-bundle": "1.0.99"}}}],
    "require": {"php": ">=8.3"},
    "autoload": {"psr-4": {"App\\\\": "src/"}},
    "config": {"allow-plugins": {"symfony/flex": true, "symfony/runtime": true}, "sort-packages": true},
    "extra": {"symfony": {"allow-contrib": true, "require": "$symfony.*"}}
}
JSON

composer require --no-interaction --no-progress symfony/flex "symfony/framework-bundle:^$symfony" \
    "symfony/console:^$symfony" "symfony/dotenv:^$symfony" "symfony/yaml:^$symfony" "symfony/runtime:^$symfony"
composer require --no-interaction --no-progress "stejac/guarded-chat-bundle:1.0.99"

# What the recipe does: register the bundle, copy the config files, add the env variable.
cp -R "$recipe/config/." config/
php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $bundles = file_get_contents("config/bundles.php");
    foreach ($manifest["bundles"] as $class => $envs) {
        $line = sprintf("    %s::class => [\"all\" => true],\n", $class);
        if (!str_contains($bundles, $class)) {
            $bundles = preg_replace("/\n\];\s*$/", "\n".$line."];\n", $bundles);
        }
    }
    file_put_contents("config/bundles.php", $bundles);
    $env = [];
    foreach ($manifest["env"] as $key => $value) {
        if (!str_starts_with($key, "#")) {
            $env[] = $key."=".$value;
        }
    }
    file_put_contents(".env", "\n".implode("\n", $env)."\n", FILE_APPEND);
' "$recipe/manifest.json"

php bin/console cache:clear
php bin/console debug:router | grep -q guarded_chat_start_session
php bin/console debug:router | grep -q guarded_chat_message
echo "OK: the bundle installs and boots on Symfony $symfony"
