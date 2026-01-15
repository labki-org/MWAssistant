# Deployment Guide

Deploy MWAssistant to a production MediaWiki instance.

## Prerequisites

- MediaWiki 1.39+
- Composer
- mw-mcp-server running (see mw-mcp-server docs)

## Installation

```bash
cd /path/to/mediawiki/extensions
git clone https://github.com/labki-org/MWAssistant.git
cd MWAssistant
composer install --no-dev
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

// Enable the extension
$wgMWAssistantEnabled = true;

// Optional: Auto-embed pages on save
$wgMWAssistantAutoEmbed = true;

// Permissions
$wgGroupPermissions['user']['mwassistant-use'] = true;
```

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
| 401 errors | Check JWT secrets match on both sides |
| Connection refused | Verify MCP server URL and network access |
