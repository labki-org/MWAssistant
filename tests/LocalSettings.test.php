<?php
// Load MWAssistant from the mount point
// Load MWAssistant from the mount point if it exists, otherwise assume standard location
wfLoadExtension( 'MWAssistant', '/mw-user-extensions/MWAssistant/extension.json' );
wfLoadExtension( 'Lockdown' );


// Stable secret key for session-cookie signing. Without this MW falls back
// to a per-process derivation; signed session cookies then fail validation
// across requests. Local-test value only — never reuse in production.
$wgSecretKey = 'mwassistant-local-dev-secret-key-DO-NOT-REUSE-7f3a9c2b1e4d6f8a';

// Pin sessions to the database. The base image leaves wgSessionCacheType at
// CACHE_ANYTHING, which falls through to wgMainCacheType=CACHE_ACCEL (APCu).
// APCu is per Apache worker process, so a request landing on a different
// worker than the one that stored the session can't find it and silently
// logs the user out. Sending sessions through SqlBagOStuff keeps them
// shared across workers and persistent across container restarts.
$wgSessionCacheType = CACHE_DB;

// Cookies don't differentiate by port, so any other labki-platform wiki
// running on localhost (e.g. the base image's own compose-wiki) would
// stomp on these cookies if we shared the default 'labki' prefix. Pick a
// distinct prefix so the two test stacks coexist without logging each
// other's sessions out.
$wgCookiePrefix = 'mwassistant';

// Secrets from old setup
$wgMWAssistantJWTMWToMCPSecret = '8n7yHEg3UttL-lEOKASg-dS_xkU0gTuqGLn7zvhg4Uh-x52rtA0Zh13WJmGd8ojDjxXJB7qR9U';
$wgMWAssistantJWTMCPToMWSecret = 'rgz5g_b6NPUlBUeZlir9XWNvnEcuOSq8bA1w2N6DUvCJROKIJKXRkyKdyPbKRio-3yh4RsHnvYQgApyYp7HEAs1Thc32wK';

// Configuration
$wgMWAssistantMCPBaseUrl = 'http://host.docker.internal:8000';
$wgMWAssistantWikiApiUrl = 'http://host.docker.internal:8890/api.php';
$wgMWAssistantEnabled = true;
$wgMWAssistantWikiId = 'test-wiki';
$wgMWAssistantAutoEmbed = true;

// Fix autolinks to use correct port
$wgServer = 'http://localhost:8890';

// Logging
$wgDebugLogGroups['mwassistant'] = '/var/log/mediawiki/mwassistant.log';
$wgShowExceptionDetails = true;

// Permissions — private wiki (matches typical production setup)
$wgGroupPermissions['*']['read'] = false;
$wgGroupPermissions['user']['read'] = true;
$wgGroupPermissions['user']['mwassistant-use'] = true;

// Whitelist pages anonymous users need
$wgWhitelistRead = [ 'Special:UserLogin', 'Special:CreateAccount', 'Main Page' ];

// Cache
$wgCacheDirectory = "$IP/cache-mwassistant";

// skin
wfLoadSkin('Citizen');
wfLoadSkin('Vector');
$wgDefaultSkin = 'vector';

// =============================================================================
// LOCKDOWN CONFIGURATION FOR ACCESS CONTROL TESTING
// =============================================================================
// Create a custom "Private" namespace that only sysops can access
// This enables testing the permission filtering in MWAssistant

// Define custom namespace IDs
define('NS_PRIVATE', 3000);
define('NS_PRIVATE_TALK', 3001);

// Register the namespaces
$wgExtraNamespaces[NS_PRIVATE] = 'Private';
$wgExtraNamespaces[NS_PRIVATE_TALK] = 'Private_talk';

// Make Private namespace content pages (not talk pages)
$wgContentNamespaces[] = NS_PRIVATE;

// Lockdown: Restrict read access to Private namespace
// Only users in 'sysop' group can read pages in NS_PRIVATE
$wgNamespacePermissionLockdown[NS_PRIVATE]['read'] = ['sysop'];
$wgNamespacePermissionLockdown[NS_PRIVATE]['edit'] = ['sysop'];
$wgNamespacePermissionLockdown[NS_PRIVATE_TALK]['read'] = ['sysop'];
$wgNamespacePermissionLockdown[NS_PRIVATE_TALK]['edit'] = ['sysop'];

// Also restrict Project namespace to test standard namespace restriction
// Only logged-in users can read Project pages (NS_PROJECT = 4)
$wgNamespacePermissionLockdown[NS_PROJECT]['read'] = ['user', 'sysop'];

// Test user for non-admin testing (create via maintenance script)
// Username: TestUser, Password: testpass123
// This user should NOT have sysop rights and therefore cannot see Private: pages
