/**
 * jsdom harness for the landing page's signature-request list.
 *
 * src/landing-page.js wires itself to the DOM at import time and immediately
 * calls loadRequests(), so the markup and a stubbed fetch have to be in place
 * before the bundle is required.
 *
 * Build the bundle first: `npm run build:test` (or just `npm test`).
 */
const path = require('node:path')
const { JSDOM } = require('jsdom')

const BUNDLE = path.join(__dirname, '..', '..', '..', 'build', 'js-test', 'landing-page.cjs')
const APP_ID = 'signdocs_brasil'

// Mirrors the parts of templates/page/index.php the script binds to.
const MARKUP = `
<div id="signdocs-app">
  <main class="signdocs-landing-main">
    <div class="signdocs-landing-actions">
      <button type="button" class="signdocs-landing-button" data-action="pick-from-files"></button>
      <button type="button" class="signdocs-landing-button" data-action="upload-local"></button>
      <button type="button" class="signdocs-landing-button" data-action="upload-from-url"></button>
    </div>
    <input type="file" id="signdocs-landing-file-input" hidden />
    <div class="signdocs-landing-status" hidden></div>
    <section class="signdocs-requests" hidden>
      <header class="signdocs-requests-header">
        <h2>Suas solicitações de assinatura</h2>
        <button type="button" class="signdocs-requests-refresh">Atualizar</button>
      </header>
      <ul class="signdocs-requests-list"></ul>
    </section>
  </main>
</div>
`

function initialStateInput(key, payload) {
	const encoded = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64')
	return `<input type="hidden" id="initial-state-${APP_ID}-${key}" value="${encoded}">`
}

/**
 * Boot the landing page with a queue of canned fetch responses.
 *
 * @param {object} [options]
 * @param {Array} [options.responses] one entry per fetch, in order. Each is
 *        either a plain value (resolved as 200 JSON) or {status, body}.
 */
function loadLanding({ responses = [] } = {}) {
	const state = { apiBase: `/apps/${APP_ID}/api/v1`, supportedMimeTypes: ['application/pdf'], maxUploadSize: 1024 }

	const dom = new JSDOM(
		`<!doctype html><html><head></head><body>${initialStateInput('signdocs_landing', state)}${MARKUP}</body></html>`,
		{ url: 'http://localhost/index.php/apps/signdocs_brasil/' },
	)

	globalThis.window = dom.window
	globalThis.self = dom.window
	// atob/btoa deliberately omitted — jsdom's call the globals internally, so
	// copying them makes jsdom recurse and every loadState decode fails.
	for (const name of ['document', 'HTMLElement', 'Element', 'Node', 'Event', 'CustomEvent', 'navigator', 'location', 'getComputedStyle', 'FormData']) {
		globalThis[name] = dom.window[name]
	}
	dom.window.OC = { requestToken: 'test-request-token' }

	const calls = []
	const queue = [...responses]
	const respond = (spec) => {
		const { status = 200, body = spec } = (spec && typeof spec === 'object' && 'status' in spec) ? spec : {}
		return Promise.resolve({
			ok: status >= 200 && status < 300,
			status,
			json: () => Promise.resolve(body ?? spec),
		})
	}
	const fetchStub = (url, init) => {
		calls.push({ url: String(url), init })
		return queue.length ? respond(queue.shift()) : respond([])
	}
	dom.window.fetch = fetchStub
	globalThis.fetch = fetchStub

	delete require.cache[require.resolve(BUNDLE)]
	require(BUNDLE)

	const $ = (sel) => dom.window.document.querySelector(sel)
	const $$ = (sel) => [...dom.window.document.querySelectorAll(sel)]

	return {
		window: dom.window,
		calls,
		/** Queue more responses for subsequent fetches. */
		enqueue(...items) {
			queue.push(...items)
		},
		section: () => $('.signdocs-requests'),
		rows: () => $$('.signdocs-request'),
		status: () => $('.signdocs-landing-status'),
		rowFor: (sessionId) => $(`.signdocs-request[data-session-id="${sessionId}"]`),
		click: (sel, root) => (root ?? dom.window.document).querySelector(sel).click(),
	}
}

/** Let queued promise callbacks run — rendering is async. */
async function flush() {
	for (let i = 0; i < 4; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

module.exports = { loadLanding, flush }
