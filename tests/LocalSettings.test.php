<?php
// Load MWAssistant from the mount point
wfLoadExtension('MWAssistant', '/mw-user-extensions/MWAssistant/extension.json');

// Secrets from old setup
$wgMWAssistantJWTMWToMCPSecret = '8n7yHEg3UttL-lEOKASg-dS_xkU0gTuqGLn7zvhg4Uh-x52rtA0Zh13WJmGd8ojDjxXJB7qR9U';
$wgMWAssistantJWTMCPToMWSecret = 'rgz5g_b6NPUlBUeZlir9XWNvnEcuOSq8bA1w2N6DUvCJROKIJKXRkyKdyPbKRio-3yh4RsHnvYQgApyYp7HEAs1Thc32wK';

// Configuration
$wgMWAssistantMCPBaseUrl = 'http://host.docker.internal:8000';
$wgMWAssistantEnabled = true;

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
