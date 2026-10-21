# MWAssistant Test Environment

This directory contains the configuration and scripts for running a local MediaWiki development environment for MWAssistant, built on top of the [**Labki Platform**](https://github.com/labki-org/labki-platform).

## Prerequisites

- Docker & Docker Compose
- The `ghcr.io/labki-org/labki-platform` image (ensure you have rights to pull or build locally). `docker-compose.yml` uses the tag in `LABKI_PLATFORM_TAG`, defaulting to `latest`. CI uses `latest-dev` (the newest MediaWiki the platform ships, currently 1.46); to match CI locally:
  ```bash
  export LABKI_PLATFORM_TAG=latest-dev
  ```

## Quick Start

The easiest way to start (or reset) the environment is using the helper script:

```bash
bash ./tests/scripts/reinstall_test_env.sh
```

or in wsl

```bash
chmod +x ./tests/scripts/reinstall_test_env.sh
./tests/scripts/reinstall_test_env.sh
```

This will:
1.  Destroy any existing containers and volumes.
2.  Start a fresh MediaWiki instance and Database.
3.  Mount the extension and configuration.

Once running, access the wiki at:
**http://localhost:8890**

- **Admin User**: `Admin`
- **Password**: `dockerpass`

## Configuration

The environment configuration is controlled by **`tests/LocalSettings.test.php`**.

This file is mounted into the container at `/mw-config/LocalSettings.user.php` and is automatically included by the platform. Use this file to:
- Load additional extensions or skins (e.g., `wfLoadSkin('Vector');`).
- Change extension settings (`$wgMWAssistantEnabled = true;`).
- Turn on auto-embedding (`$wgMWAssistantAutoEmbed = true;`). It is off by default because it calls the MCP server at `host.docker.internal`, which does not resolve on Linux/CI.

The JWT secrets and `$wgSecretKey` in this file are for the local test wiki only. Never reuse them in a real deployment.
- Configure debugging.

## Common Operations

### View Logs
MediaWiki logs (including MWAssistant specific logs) are written to stdout or the configured log file.
```bash
docker compose logs -f wiki
```

### Run Maintenance Scripts
To run a maintenance script inside the container:
```bash
docker compose exec wiki php maintenance/run.php <script_name>
```

### Check Extension Status
```bash
docker compose exec wiki php maintenance/run.php eval 'echo ExtensionRegistry::getInstance()->isLoaded("MWAssistant") ? "Loaded" : "Not Loaded";'
```

## Running PHPUnit

The PHPUnit tests (`tests/phpunit/`) run inside the wiki container, the same way CI does. With the environment running (see Quick Start):

```bash
# The platform image bakes in its own copy of MWAssistant and its autoloader
# points there; replace it with this checkout so the tests exercise your code.
docker compose exec -T -w /var/www/html wiki sh -c \
  'rm -rf extensions/MWAssistant && ln -s /mw-user-extensions/MWAssistant extensions/MWAssistant'
docker compose exec -T wiki php maintenance/run.php update --quick
```

On MediaWiki 1.46+ (which no longer ships `tests/phpunit/phpunit.php`), generate a PHPUnit config once per container:

```bash
MW_VERSION=$(docker compose exec -T -w /var/www/html wiki \
  sed -n "s/^define( 'MW_VERSION', '\(.*\)' );/\1/p" includes/Defines.php)
docker compose exec -T -w /var/www/html wiki curl -sfL -o phpunit.xml.template \
  "https://raw.githubusercontent.com/wikimedia/mediawiki/${MW_VERSION}/phpunit.xml.template"
docker compose exec -T -w /var/www/html wiki php tests/phpunit/generatePHPUnitConfig.php
```

Then run each suite (PHPUnit 9 only runs its first path argument):

```bash
docker compose exec -T -w /var/www/html -e MEDIAWIKI_HAS_INTEGRATION_TESTS=1 wiki \
  php vendor/bin/phpunit --testdox /mw-user-extensions/MWAssistant/tests/phpunit/integration/
```

On older MediaWiki images, use `php tests/phpunit/phpunit.php` instead of `php vendor/bin/phpunit`. `MEDIAWIKI_HAS_INTEGRATION_TESTS=1` stops the 1.46 bootstrap from guessing "unit tests only" from the path and skipping LocalSettings.

## CI

`.github/workflows/ci.yml` runs on pushes and pull requests to `main`:

- **Lint**: `php -l` over `includes/` and `tests/`, and `node --check` over `resources/*.js`. A `.phpcs.xml` (MediaWiki code style) exists but is not enforced yet; run it with `composer install && composer phpcs`.
- **PHPUnit**: starts this compose stack with `LABKI_PLATFORM_TAG=latest-dev` and runs the steps above.
