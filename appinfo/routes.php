<?php

declare(strict_types=1);

return [
	'routes' => [
		// Top-level navigation entry — landing page with the three intake paths.
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Signing sessions
		['name' => 'signing#create', 'url' => '/api/v1/sessions', 'verb' => 'POST'],
		['name' => 'signing#listForUser', 'url' => '/api/v1/sessions', 'verb' => 'GET'],
		['name' => 'signing#listForFile', 'url' => '/api/v1/files/{fileId}/sessions', 'verb' => 'GET',
			'requirements' => ['fileId' => '\d+']],
		// Cancel a flow: single session, or every session inside an envelope.
		['name' => 'signing#cancel', 'url' => '/api/v1/sessions/{sessionId}/cancel', 'verb' => 'POST'],
		// Mint a fresh link for the caller's OWN signature. POST because it
		// issues a new credential every time — nothing here is cacheable.
		['name' => 'signing#ownLink', 'url' => '/api/v1/sessions/{sessionId}/own-link', 'verb' => 'POST'],

		// Document intake — convert local upload / remote URL into a NC fileId.
		// The existing /api/v1/sessions then handles the rest of the flow.
		['name' => 'intake#fromUpload', 'url' => '/api/v1/intake/upload', 'verb' => 'POST'],
		['name' => 'intake#fromUrl', 'url' => '/api/v1/intake/url', 'verb' => 'POST'],

		// OAuth device-flow + per-user settings
		['name' => 'settings#startDeviceFlow', 'url' => '/api/v1/oauth/device', 'verb' => 'POST'],
		['name' => 'settings#pollDeviceFlow', 'url' => '/api/v1/oauth/poll', 'verb' => 'POST'],
		['name' => 'settings#disconnect', 'url' => '/api/v1/oauth/disconnect', 'verb' => 'POST'],
		['name' => 'settings#setSignedFolder', 'url' => '/api/v1/settings/signed-folder', 'verb' => 'POST'],

		// Admin settings
		['name' => 'settings#adminUpdate', 'url' => '/api/v1/admin/settings', 'verb' => 'POST'],
		['name' => 'settings#testConnection', 'url' => '/api/v1/admin/test-connection', 'verb' => 'POST'],

		// Webhook receiver (public, HMAC-verified)
		['name' => 'webhook#receive', 'url' => '/api/v1/webhook', 'verb' => 'POST'],
	],
];
