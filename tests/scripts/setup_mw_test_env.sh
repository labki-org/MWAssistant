#!/usr/bin/env bash
set -euo pipefail

#
# MWAssistant — MediaWiki test environment setup script (SQLite)
#

get_cache_dir() {
    case "$(uname -s)" in
        Darwin*) echo "$HOME/Library/Caches/mwassistant" ;;
        MINGW*|MSYS*|CYGWIN*)
            local appdata="${LOCALAPPDATA:-$HOME/AppData/Local}"
            echo "$appdata/mwassistant"
            ;;
        *) echo "${XDG_CACHE_HOME:-$HOME/.cache}/mwassistant" ;;
    esac
}

# ---------------- CONFIG ----------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE_BASE="$(get_cache_dir)"
MW_DIR="${MW_DIR:-$CACHE_BASE/mediawiki-MWAssistant-test}"
# If script is in tests/scripts, ext dir is two levels up
EXT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
MW_BRANCH=REL1_44
MW_PORT=8890
MW_ADMIN_USER=Admin
MW_ADMIN_PASS=dockerpass
MW_JWT_MW_TO_MCP_SECRET=8n7yHEg3UttL-lEOKASg-dS_xkU0gTuqGLn7zvhg4Uh-x52rtA0Zh13WJmGd8ojDjxXJB7qR9U
MW_JWT_MCP_TO_MW_SECRET=rgz5g_b6NPUlBUeZlir9XWNvnEcuOSq8bA1w2N6DUvCJROKIJKXRkyKdyPbKRio-3yh4RsHnvYQgApyYp7HEAs1Thc32wK

CONTAINER_WIKI="/var/www/html/w"
CONTAINER_LOG_DIR="/var/log/mwassistant"
CONTAINER_LOG_FILE="$CONTAINER_LOG_DIR/mwassistant.log"
LOG_DIR="$EXT_DIR/logs"

echo "==> Using MW directory: $MW_DIR"

# ---------------- RESET ENV (FULL) ----------------

if [ -d "$MW_DIR" ]; then
    cd "$MW_DIR"
    echo "==> Shutting down existing containers and removing volumes..."
    docker compose down -v || true
fi

echo "==> Ensuring MediaWiki core exists..."
if [ ! -d "$MW_DIR/.git" ]; then
    mkdir -p "$(dirname "$MW_DIR")"
    git clone https://gerrit.wikimedia.org/r/mediawiki/core.git "$MW_DIR"
fi

cd "$MW_DIR"

echo "==> Resetting MediaWiki core to clean $MW_BRANCH..."
git fetch --all
git checkout "$MW_BRANCH"
git reset --hard "$MW_BRANCH"
git clean -fdx
git submodule update --init --recursive || true

# ---------------- DOCKER ENV ----------------

cat > "$MW_DIR/.env" <<EOF
MW_SCRIPT_PATH=/w
MW_SERVER=http://localhost:$MW_PORT
MW_DOCKER_PORT=$MW_PORT
MEDIAWIKI_USER=$MW_ADMIN_USER
MEDIAWIKI_PASSWORD=$MW_ADMIN_PASS
MW_DOCKER_UID=$(id -u)
MW_DOCKER_GID=$(id -g)
EOF

echo "==> Starting MW containers..."
docker compose up -d

echo "==> Installing composer deps (core only)..."
docker compose exec -T mediawiki composer update --no-interaction --no-progress

echo "==> Running MediaWiki install script..."
# LocalSettings.php must not reference extensions yet
docker compose exec -T mediawiki bash -lc "rm -f $CONTAINER_WIKI/LocalSettings.php"
docker compose exec -T mediawiki /bin/bash /docker/install.sh

echo "==> Fixing SQLite permissions..."
docker compose exec -T mediawiki bash -lc "chmod -R o+rwx $CONTAINER_WIKI/cache/sqlite || true"

# ---------------- EXTENSION & LOG MOUNTS ----------------

echo "==> Preparing host log directory..."
mkdir -p "$LOG_DIR"
chmod 777 "$LOG_DIR" || true

echo "==> Writing docker-compose.override.yml..."
cat > "$MW_DIR/docker-compose.override.yml" <<EOF
services:
  mediawiki:
    user: "$(id -u):$(id -g)"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - $EXT_DIR:/var/www/html/w/extensions/MWAssistant:cached
      - $LOG_DIR:$CONTAINER_LOG_DIR
EOF

echo "==> Restarting with MWAssistant mount..."
docker compose down
docker compose up -d

# ---------------- INSTALL SEMANTIC MEDIAWIKI ----------------

echo "==> Installing SMW via composer..."
docker compose exec -T mediawiki bash -lc "
  cd $CONTAINER_WIKI
  composer require mediawiki/semantic-media-wiki:'~6.0' --no-progress
"

echo "==> Enabling SMW..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/SemanticMediaWiki/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === Semantic MediaWiki ==='
    echo 'wfLoadExtension(\"SemanticMediaWiki\");'
    echo 'enableSemantics(\"localhost\");'
    echo '\$smwgChangePropagationProtection = false;'
    echo '\$smwgEnabledDeferredUpdate = false;'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for SMW..."
docker compose exec -T mediawiki php maintenance/update.php --quick

echo "==> Initializing SMW store..."
docker compose exec -T mediawiki php extensions/SemanticMediaWiki/maintenance/setupStore.php --nochecks

# ---------------- ENABLE PARSER FUNCTIONS ----------------

echo "==> Enabling ParserFunctions..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/ParserFunctions/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === ParserFunctions ==='
    echo 'wfLoadExtension(\"ParserFunctions\");'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

# ---------------- MWAssistant ----------------

echo "==> Verifying MWAssistant extension directory..."
docker compose exec -T mediawiki bash -lc "
  if [ ! -d $CONTAINER_WIKI/extensions/MWAssistant ]; then
    echo 'ERROR: MWAssistant extension directory not found!'
    exit 1
  fi
  if [ ! -f $CONTAINER_WIKI/extensions/MWAssistant/extension.json ]; then
    echo 'ERROR: MWAssistant extension.json not found!'
    exit 1
  fi
  echo '✓ MWAssistant extension directory found'
"

echo "==> Enabling MWAssistant..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/MWAssistant/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === MWAssistant ==='
    echo 'wfLoadExtension(\"MWAssistant\");'
    echo '\$wgDebugLogGroups[\"mwassistant\"] = \"$CONTAINER_LOG_FILE\";'
    echo '\$wgMWAssistantMCPBaseUrl = \"http://host.docker.internal:8000\";'
    echo '\$wgMWAssistantJWTMWToMCPSecret = \"$MW_JWT_MW_TO_MCP_SECRET\";'
    echo '\$wgMWAssistantJWTMCPToMWSecret = \"$MW_JWT_MCP_TO_MW_SECRET\";'
    echo '\$wgMWAssistantEnabled = true;'
    echo '\$wgGroupPermissions[\"user\"][\"mwassistant-use\"] = true;'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for MWAssistant..."
docker compose exec -T mediawiki php maintenance/update.php --quick

# ---------------- CACHE DIRECTORY ----------------

echo "==> Setting cache directory..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/wgCacheDirectory/d' $CONTAINER_WIKI/LocalSettings.php
  sed -i '/\\$IP = __DIR__/a \$wgCacheDirectory = \"\$IP/cache-mwassistant\";' $CONTAINER_WIKI/LocalSettings.php
"

# ---------------- REBUILD L10N ----------------

echo "==> Rebuilding LocalisationCache..."
docker compose exec -T mediawiki php maintenance/rebuildLocalisationCache.php --force

# ---------------- TEST EXTENSION LOAD ----------------

echo "==> Testing MWAssistant loading..."
docker compose exec -T mediawiki php -r "
define('MW_INSTALL_PATH','/var/www/html/w');
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once MW_INSTALL_PATH . '/includes/WebStart.php';
echo ExtensionRegistry::getInstance()->isLoaded('MWAssistant')
    ? \"✓ MWAssistant loaded\n\"
    : \"ERROR: MWAssistant NOT loaded\n\";
"

echo "==> Logging test..."
docker compose exec -T mediawiki php -r "
wfDebugLog('mwassistant', 'MWAssistant test log '.date('H:i:s'));
echo \"OK\n\";
"

docker compose exec -T mediawiki tail -n 5 "$CONTAINER_LOG_FILE" || echo "No log yet."

# ---------------- COMPLETE ----------------

echo ""
echo "========================================"
echo " DONE — MWAssistant test environment ready "
echo "========================================"
echo "Visit http://localhost:$MW_PORT/w"
echo "Admin: $MW_ADMIN_USER / $MW_ADMIN_PASS"
echo "Logs: $LOG_DIR"
