# MWAssistant

MWAssistant is a MediaWiki extension that integrates with an MCP (Model Context Protocol) server ([mw-mcp-server](https://github.com/labki-org/mw-mcp-server)) to provide AI assistance within the wiki.

Requires MediaWiki 1.44+ and PHP 8.2+ (MediaWiki 1.46 / PHP 8.3 recommended).

## Features

- **Chat Interface**: A special page (`Special:MWAssistant`) for conversational AI assistance, streamed via `Special:MWAssistantStream`.
- **Edit Assistance**: An "Ask Assistant" button on edit pages to help with drafting, summarizing, and improving wikitext.
- **Search shortcut**: An "Ask MW Assistant" icon in the search bar that sends question-like queries to the chat.
- **Search & Query**: Semantic (vector) search, keyword search and Semantic MediaWiki queries, exposed to the MCP server through `action=mwassistant-*` API modules.
- **Embeddings**: Optional automatic embedding of pages on save/move/delete, and `Special:MWAssistantEmbeddings` to inspect and re-embed pages.
- **Secure Integration**: Uses JWT signing to authenticate users with the MCP server, passing user groups so results respect page permissions.

## Installation

1. Clone the extension into your `extensions/` directory. It has no runtime Composer dependencies.
   ```bash
   cd extensions/
   git clone https://github.com/labki-org/MWAssistant.git
   ```

2. Add the following to your `LocalSettings.php`:
   ```php
   wfLoadExtension( 'MWAssistant' );

   $wgMWAssistantMCPBaseUrl = 'http://mw-mcp-server:8000'; // URL of your MCP server
   $wgMWAssistantWikiId = 'my-wiki';                        // required, unique per wiki

   // JWT authentication (separate secrets for each direction).
   // Load these from the environment rather than committing them.
   $wgMWAssistantJWTMWToMCPSecret = getenv( 'JWT_MW_TO_MCP_SECRET' );
   $wgMWAssistantJWTMCPToMWSecret = getenv( 'JWT_MCP_TO_MW_SECRET' );

   // Permissions: allow all registered users to use MWAssistant
   $wgGroupPermissions['user']['mwassistant-use'] = true;
   ```

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for a production checklist.

## Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| `$wgMWAssistantMCPBaseUrl` | `http://localhost:8000` | Base URL of the MCP server's HTTP interface. |
| `$wgMWAssistantWikiId` | `""` | **Required.** Unique identifier for this wiki; the MCP server uses it to keep each wiki's data (e.g. embeddings) separate. |
| `$wgMWAssistantJWTMWToMCPSecret` | `""` | **Required.** Secret used to sign tokens sent from MediaWiki to the MCP server. |
| `$wgMWAssistantJWTMCPToMWSecret` | `""` | **Required.** Secret used to verify tokens the MCP server sends to MediaWiki's API. |
| `$wgMWAssistantWikiApiUrl` | `""` | URL of this wiki's `api.php` as reachable *from the MCP server*. If empty, it is built from `$wgServer` + `$wgScriptPath`, which is often wrong when the MCP server runs in another container or network. |
| `$wgMWAssistantEnabled` | `true` | Set to `false` to disable the assistant UI, auto-embedding and the special pages. |
| `$wgMWAssistantAutoEmbed` | `false` | Queue a job to (re)embed a page in the MCP server when it is saved, moved or deleted. Requires a job runner (see below). |
| `$wgMWAssistantEmbedSkipNamespaces` | `[ 2 ]` | Namespace IDs never auto-embedded. The default skips `User:` pages. Set to `[]` to embed everything. |
| `$wgMWAssistantEmbedTalkPages` | `false` | Whether talk pages are auto-embedded. |
| `$wgMWAssistantJWTTTL` | `30` | Lifetime in seconds of the JWTs MediaWiki issues to the MCP server. |

### Rights

| Right | Default | Grants |
|-------|---------|--------|
| `mwassistant-use` | not granted | Use the chat, editor assistant and search shortcut. |
| `mwassistant-manage-embeddings` | `sysop` | Trigger re-embeds from `Special:MWAssistantEmbeddings`. |

### Auto-embedding needs a job runner

With `$wgMWAssistantAutoEmbed = true`, page changes enqueue `mwassistantUpdateEmbedding` / `mwassistantDeleteEmbedding` jobs. They only run if jobs are being executed: either a dedicated runner (`php maintenance/run.php runJobs --wait` under a service manager) or `$wgJobRunRate > 0`. If jobs are not run, embeddings silently go stale.

### Streaming behind a reverse proxy

Chat responses are streamed with Server-Sent Events from `Special:MWAssistantStream`. Any proxy or compression layer that buffers the response will hold the whole answer until it finishes. For that path, disable response buffering and compression:

- **Caddy**: `reverse_proxy` with `flush_interval -1`, and no `encode` for the stream path.
- **nginx**: the page sends `X-Accel-Buffering: no`, which disables proxy buffering; also make sure `gzip` is off for the location.
- **Apache**: exclude the path from `mod_deflate`, e.g. `SetEnvIf Request_URI "MWAssistantStream" no-gzip dont-vary`; with `mod_proxy`, add `flushpackets=on` to the `ProxyPass`.
- **Cloudflare / CDNs**: bypass caching for the path.

## Usage

### Chat
Navigate to `Special:MWAssistant` to start a chat session.

### Editor
On any edit page, click the "Ask Assistant" button in the toolbar (or above the text area) to open the assistant sidebar and request help with the selected text or the entire content.

## Architecture

The extension acts as a frontend for the MCP server.
- **Frontend**: JS/CSS modules for the chat, editor, embeddings and search-bar UI.
- **Backend (PHP)**:
  - Generates HS256 JWTs identifying the MediaWiki user and their groups.
  - Proxies requests to the MCP server via an internal HTTP client.
  - Exposes `action=mwassistant-*` API modules that the MCP server calls back into (authenticated with JWTs signed by the MCP-to-MW secret).

## Development

See [tests/README.md](tests/README.md) for the Docker test wiki and running PHPUnit. Lint locally with `composer install`, then `composer phpcs` (the MediaWiki code style is not yet enforced; the existing code has many whitespace violations that `composer fix` can correct).
