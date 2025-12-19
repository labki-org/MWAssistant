# MWAssistant Test Environment

This directory contains the configuration and scripts for running a local MediaWiki development environment for MWAssistant, built on top of the [**Labki Platform**](https://github.com/labki-org/labki-platform).

## Prerequisites

- Docker & Docker Compose
- `ghcr.io/labki-org/labki-platform:latest` image (ensure you have rights to pull or build locally).

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
