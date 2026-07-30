<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Top-level navigation entry. Renders the "Request signature" landing page
 * with three intake paths: pick from Files, upload from disk, fetch by URL.
 *
 * Each path's job is to produce a NC fileId and hand it off to the existing
 * SigningController::create flow — the landing page is a UX funnel, not a
 * second signing pipeline.
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('signdocs_landing', [
			'apiBase' => '/apps/' . Application::APP_ID . '/api/v1',
			'supportedMimeTypes' => [
				'application/pdf',
				'application/vnd.oasis.opendocument.text',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/msword',
			],
			'maxUploadSize' => 50 * 1024 * 1024, // 50MB — surfaced in the UI
			// Same address the signing dialog reads on the Files surface; the
			// dialog is shared, so it must be reachable from here too.
			'userEmail' => $this->userSession->getUser()?->getEMailAddress() ?? '',
		]);

		return new TemplateResponse(Application::APP_ID, 'page/index');
	}
}
