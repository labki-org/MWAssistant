# Deployment Guide

Deploy MWAssistant to a production MediaWiki instance.

## Prerequisites

- MediaWiki 1.44+ and PHP 8.2+ (MediaWiki 1.46 with PHP 8.3 recommended)
- mw-mcp-server running (see mw-mcp-server docs)
- A job runner, if you enable `$wgMWAssistantAutoEmbed` (see below)

MWAssistant has no runtime Composer dependencies, so no `composer install`
is needed for production.

## Installation

```bash
cd /path/to/mediawiki/extensions
git clone https://github.com/labki-org/MWAssistant.git
```

## Configuration

Add to `LocalSettings.php`:

```php
wfLoadExtension('MWAssistant');

// MCP Server URL
$wgMWAssistantMCPBaseUrl = 'https://mcp.example.com';

// Unique wiki identifier (required for multi-tenant)
$wgMWAssistantWikiId = 'my-wiki-1';

// JWT Secrets (load from environment for security)
$wgMWAssistantJWTMWToMCPSecret = getenv('JWT_MW_TO_MCP_SECRET');
$wgMWAssistantJWTMCPToMWSecret = getenv('JWT_MCP_TO_MW_SECRET');

// API URL the MCP server uses to call back into this wiki. Defaults to
// $wgServer . $wgScriptPath . '/api.php', which is often not reachable from
// the MCP server's network.
$wgMWAssistantWikiApiUrl = 'https://wiki.example.com/w/api.php';

// Optional: Auto-embed pages on save/move/delete (requires a job runner)
$wgMWAssistantAutoEmbed = true;

// Permissions
$wgGroupPermissions['user']['mwassistant-use'] = true;
// Triggering re-embeds from Special:MWAssistantEmbeddings needs
// 'mwassistant-manage-embeddings', granted to sysop by default.
```

See the [README](../README.md#configuration-options) for every option.

## Job Runner

Auto-embedding enqueues `mwassistantUpdateEmbedding` and
`mwassistantDeleteEmbedding` jobs. Run them with a dedicated runner, e.g.
`php maintenance/run.php runJobs --wait` under systemd/supervisord, or set
`$wgJobRunRate > 0`. Without one, embeddings never update.

## Reverse Proxy (streaming)

Chat answers are streamed as Server-Sent Events from
`Special:MWAssistantStream`. Disable response buffering and compression for
that path, or users see the whole answer only once it is finished:

- Caddy: `reverse_proxy ... { flush_interval -1 }`, and skip `encode` for it.
- nginx: the page sends `X-Accel-Buffering: no`; keep `gzip` off for it.
- Apache: `SetEnvIf Request_URI "MWAssistantStream" no-gzip dont-vary`
  (mod_deflate); with mod_proxy add `flushpackets=on`.

## Generating Secrets

Both MediaWiki and the MCP server must share the same secrets:

```bash
# Generate two 64-character secrets
openssl rand -base64 48  # For JWT_MW_TO_MCP_SECRET
openssl rand -base64 48  # For JWT_MCP_TO_MW_SECRET
```

Set these in:
1. MediaWiki's environment or `LocalSettings.php`
2. MCP server's `.env` file

## Multi-Wiki Setup

If running multiple wikis with one MCP server:

| Wiki | `$wgMWAssistantWikiId` | Data Location |
|------|------------------------|---------------|
| wiki-alpha | `wiki-alpha` | `/app/data/wiki-alpha/` |
| wiki-beta | `wiki-beta` | `/app/data/wiki-beta/` |

Each wiki gets isolated vector embeddings.

## Verification

1. Navigate to `Special:MWAssistant`
2. Try a chat message
3. Check MediaWiki logs for errors

```bash
# Check extension loaded
php maintenance/run.php eval \
  'echo ExtensionRegistry::getInstance()->isLoaded("MWAssistant") ? "OK" : "FAIL";'
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "wiki_id missing" | Set `$wgMWAssistantWikiId` in LocalSettings.php |
| 403 `invalid_jwt` from `action=mwassistant-*` | The MCP server's token failed verification: check `JWT_MCP_TO_MW_SECRET` matches `$wgMWAssistantJWTMCPToMWSecret` and that server clocks are in sync |
| Auth errors (401/403) from the MCP server when chatting | Check `JWT_MW_TO_MCP_SECRET` matches `$wgMWAssistantJWTMWToMCPSecret` |
| Chat answer appears all at once | A proxy is buffering or compressing `Special:MWAssistantStream` (see above) |
| Embeddings not updating | No job runner, or the namespace is in `$wgMWAssistantEmbedSkipNamespaces` |
| Connection refused | Verify MCP server URL and network access |
