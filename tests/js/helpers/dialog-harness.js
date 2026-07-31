/**
 * jsdom harness for the Files-app signing dialog.
 *
 * The dialog is plain DOM built by src/files-action.js. That module runs
 * side effects at import time — it reads initial state, registers the file
 * action, and subscribes to the `signdocs:open-dialog` event — and the
 * @nextcloud/* packages it pulls in expect browser globals to already exist.
 * So every load here builds a fresh jsdom window, publishes its globals, and
 * only then requires the CommonJS test build.
 *
 * Build the bundle first: `npm run build:test` (or just `npm test`).
 */
const path = require('node:path')
const { JSDOM } = require('jsdom')

const BUNDLE = path.join(__dirname, '..', '..', '..', 'build', 'js-test', 'files-action.cjs')
const APP_ID = 'signdocs_brasil'

// Globals @nextcloud/files and the dialog itself reach for. jsdom owns them;
// we just lift them onto globalThis because the bundle is browser code.
//
// atob/btoa are deliberately NOT in this list: jsdom's implementations call
// the global ones internally, so copying them over makes jsdom recurse into
// itself and every decode fails with "invalid characters". Node's built-ins
// are spec-compatible and are what loadState() ends up using.
const BROWSER_GLOBALS = [
	'document', 'HTMLElement', 'Element', 'Node', 'Event', 'CustomEvent',
	'navigator', 'location', 'getComputedStyle',
]

/**
 * Serialize an initial-state payload the way Nextcloud's IInitialState does:
 * a hidden input carrying base64 JSON, which loadState() finds by id.
 */
function initialStateInput(key, payload) {
	const encoded = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64')
	return `<input type="hidden" id="initial-state-${APP_ID}-${key}" value="${encoded}">`
}

/**
 * Boot a dialog instance.
 *
 * @param {object} [options]
 * @param {string} [options.userEmail] value for the `userEmail` initial state —
 *        the NC user's profile address, which decides whether the review step
 *        promises invite emails. Omit to simulate a profile with no email.
 * @return {object} the module's exports plus the jsdom window and helpers.
 */
function loadDialog({ userEmail } = {}) {
	const state = {
		apiBase: `/apps/${APP_ID}/api/v1`,
		supportedMimeTypes: ['application/pdf'],
	}
	if (userEmail !== undefined) {
		state.userEmail = userEmail
	}

	const dom = new JSDOM(
		`<!doctype html><html><head></head><body>${initialStateInput('signdocs', state)}</body></html>`,
		{ url: 'http://localhost/index.php/apps/files/' },
	)

	globalThis.window = dom.window
	globalThis.self = dom.window
	for (const name of BROWSER_GLOBALS) {
		globalThis[name] = dom.window[name]
	}

	// The dialog reads window.OC.requestToken for the CSRF header, and alerts
	// on validation failures — capture both instead of letting jsdom no-op.
	dom.window.OC = { requestToken: 'test-request-token' }
	const alerts = []
	dom.window.alert = (message) => alerts.push(String(message))
	globalThis.alert = dom.window.alert

	// Recording fetch. This has to be in place *before* the bundle loads:
	// @nextcloud/router binds window.fetch at import time, and jsdom ships no
	// fetch of its own, so a missing stub crashes the require below.
	const fetchCalls = []
	let fetchImpl = () => Promise.reject(new Error('fetch not stubbed for this test'))
	const recordingFetch = (...args) => {
		fetchCalls.push(args)
		return fetchImpl(...args)
	}
	dom.window.fetch = recordingFetch
	globalThis.fetch = recordingFetch

	// @nextcloud/files keeps its action registry on the window, so a fresh
	// window is enough to avoid duplicate-registration errors — but the
	// module cache is process-wide and must be dropped explicitly.
	delete require.cache[require.resolve(BUNDLE)]
	const mod = require(BUNDLE)

	return {
		...mod,
		window: dom.window,
		alerts,
		fetchCalls,
		/** Install the response the next confirm-and-send should receive. */
		stubFetch(impl) {
			fetchImpl = impl
		},
		/** Resolve an OK response carrying the given JSON body. */
		ok(body) {
			return () => Promise.resolve({ ok: true, json: () => Promise.resolve(body) })
		},
		/** Resolve a failed response carrying the given problem message. */
		fails(message) {
			return () => Promise.resolve({ ok: false, json: () => Promise.resolve({ message }) })
		},
		/** Open the per-document status panel. */
		openStatusDialog(fileInfo) {
			dom.window.document.querySelectorAll('.signdocs-overlay').forEach((o) => o.remove())
			return mod.openStatusDialog(fileInfo)
		},
		/** Open the dialog for a file, discarding any previously open one. */
		openDialog(fileInfo = { id: 42, name: 'contrato.pdf', mime: 'application/pdf' }) {
			dom.window.document.querySelectorAll('.signdocs-overlay').forEach((o) => o.remove())
			mod.openSigningDialog(fileInfo)
			return dialogHandles(dom, dom.window.document.querySelector('.signdocs-overlay'))
		},
	}
}

function dialogHandles(dom, overlay) {
	const form = overlay.querySelector('form')
	const modeSelect = overlay.querySelector('select[name=mode]')

	return {
		overlay,
		form,
		modeSelect,
		orderSelect: overlay.querySelector('select[name=order]'),
		orderHint: overlay.querySelector('.signdocs-order-locked-hint'),
		sequentialHint: overlay.querySelector('.signdocs-sequential-hint'),
		signerList: overlay.querySelector('.signdocs-signer-list'),
		confirmPanel: overlay.querySelector('.signdocs-confirm'),
		result: overlay.querySelector('.signdocs-result'),

		/** Values currently offered by the order dropdown, in DOM order. */
		orderOptions() {
			return [...overlay.querySelector('select[name=order]').options].map((o) => o.value)
		},

		setMode(value) {
			modeSelect.value = value
			modeSelect.dispatchEvent(new dom.window.Event('change'))
		},

		/** Fill the signer rows, adding rows as needed. */
		fillSigners(rows) {
			const list = overlay.querySelector('.signdocs-signer-list')
			while (list.querySelectorAll('.signdocs-signer-row').length < rows.length) {
				overlay.querySelector('.signdocs-add-signer').click()
			}
			;[...list.querySelectorAll('.signdocs-signer-row')].forEach((row, i) => {
				if (!rows[i]) return
				row.querySelector('input[name=name]').value = rows[i].name
				row.querySelector('input[name=email]').value = rows[i].email
				row.querySelector('input[name=cpf_cnpj]').value = rows[i].doc
			})
		},

		/** Submit the form — advances to the review step, sends nothing. */
		submit() {
			form.dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }))
		},

		clickConfirm() {
			overlay.querySelector('.signdocs-confirm-send').click()
		},

		clickBack() {
			overlay.querySelector('.signdocs-confirm-back').click()
		},

		confirmText() {
			return overlay.querySelector('.signdocs-confirm').textContent
		},

		confirmSigners() {
			return [...overlay.querySelectorAll('.signdocs-confirm-signer-list li')]
		},
	}
}

/** Let queued promise callbacks run — the confirm handler is async. */
async function flush() {
	await new Promise((resolve) => setImmediate(resolve))
	await new Promise((resolve) => setImmediate(resolve))
}

module.exports = { loadDialog, flush, APP_ID }
