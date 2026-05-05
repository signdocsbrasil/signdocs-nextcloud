<?php

declare(strict_types=1);

return [
	'routes' => [
		// Signing sessions
		['name' => 'signing#create', 'url' => '/api/v1/sessions', 'verb' => 'POST'],
		['name' => 'signing#listForUser', 'url' => '/api/v1/sessions', 'verb' => 'GET'],
		['name' => 'signing#listForFile', 'url' => '/api/v1/files/{fileId}/sessions', 'verb' => 'GET',
			'requirements' => ['fileId' => '\d+']],

		// OAuth device-flow + per-user settings
		['name' => 'settings#startDeviceFlow', 'url' => '/api/v1/oauth/device', 'verb' => 'POST'],
		['name' => 'settings#pollDeviceFlow', 'url' => '/api/v1/oauth/poll', 'verb' => 'POST'],
		['name' => 'settings#disconnect', 'url' => '/api/v1/oauth/disconnect', 'verb' => 'POST'],
		['name' => 'settings#setSignedFolder', 'url' => '/api/v1/settings/signed-folder', 'verb' => 'POST'],

		// Admin settings
		['name' => 'settings#adminUpdate', 'url' => '/api/v1/admin/settings', 'verb' => 'POST'],

		// Webhook receiver (public, HMAC-verified)
		['name' => 'webhook#receive', 'url' => '/api/v1/webhook', 'verb' => 'POST'],
	],
];
