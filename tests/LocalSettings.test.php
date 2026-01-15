<?php
// Load MWAssistant from the mount point
wfLoadExtension('MWAssistant', '/mw-user-extensions/MWAssistant/extension.json');

// Secrets from old setup
$wgMWAssistantJWTMWToMCPSecret = '8n7yHEg3UttL-lEOKASg-dS_xkU0gTuqGLn7zvhg4Uh-x52rtA0Zh13WJmGd8ojDjxXJB7qR9U';
$wgMWAssistantJWTMCPToMWSecret = 'rgz5g_b6NPUlBUeZlir9XWNvnEcuOSq8bA1w2N6DUvCJROKIJKXRkyKdyPbKRio-3yh4RsHnvYQgApyYp7HEAs1Thc32wK';

// Configuration
$wgMWAssistantMCPBaseUrl = 'http://host.docker.internal:8000';
$wgMWAssistantEnabled = true;
$wgMWAssistantWikiId = 'test-wiki';

// Logging
$wgDebugLogGroups['mwassistant'] = '/var/log/mediawiki/mwassistant.log';
$wgShowExceptionDetails = true;

// Permissions
$wgGroupPermissions['user']['mwassistant-use'] = true;

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
