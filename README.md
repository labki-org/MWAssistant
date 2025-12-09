# MWAssistant

MWAssistant is a MediaWiki extension that integrates with an MCP (Model Context Protocol) server to provide AI assistance within the wiki.

## Features

- **Chat Interface**: A special page (`Special:MWAssistant`) for conversational AI assistance.
- **Edit Assistance**: An "AI Assist" button on edit pages to help with drafting, summarizing, and improving wikitext.
- **Search & Query**: Integration with wiki search and Semantic MediaWiki queries (backend support included).
- **Secure Integration**: Uses JWT signing to securely authenticate users with the MCP server, passing user roles.

## Installation

1. Clone or download this extension into your `extensions/` directory.
   ```bash
   cd extensions/
   git clone <repo-url> MWAssistant
   ```

2. Add the following to your `LocalSettings.php`:
   ```php
   wfLoadExtension( 'MWAssistant' );

   // Configuration
   $wgMWAssistantMCPBaseUrl = 'http://mw-mcp-server:8000'; // URL of your MCP server
   $wgMWAssistantJWTSecret = 'your-secret-key'; // Must match the MCP server's secret
   $wgMWAssistantEnabled = true;

   // Permissions
   // Allow all registered users to use MWAssistant
   $wgGroupPermissions['user']['mwassistant-use'] = true;
   // Or restrict to specific groups
   // $wgGroupPermissions['sysop']['mwassistant-use'] = true;
   ```

## Configuration Options

- `MWAssistantMCPBaseUrl`: The base URL of the MCP server HTTP interface (e.g. `http://localhost:8000`).
- `MWAssistantJWTSecret`: Shared secret for signing JWTs.
- `MWAssistantEnabled`: Master switch to enable/disable the extension features.

## Usage

### Chat
Navigate to `Special:MWAssistant` to start a chat session.

### Editor
On any edit page, click the "AI Assist" button in the toolbar (or near the text area) to request help with the selected text or the entire content.

## Architecture

The extension acts as a frontend for the MCP server.
- **Frontend**: JS/CSS modules for Chat and Editor UI.
- **Backend (PHP)**: 
  - Generates HS256 JWTs identifying the MediaWiki user and their groups.
  - Proxies requests to the MCP server via internal HTTP client.
  - Exposes `action=mwassistant-*` API modules for the frontend.
