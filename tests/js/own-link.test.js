/**
 * Recovering your own signing link.
 *
 * When you are a signer on your own send, SignDocs dispatches no invitation —
 * the addresses match — so the link exists only in the dialog that showed it.
 * Close that dialog and the document becomes unsignable. The row offers to mint
 * a fresh one, and the URL is navigated to rather than shown: it is a bearer
 * credential, and putting it on screen is what this whole change avoids.
 *
 * Which rows qualify is decided server-side (SigningSessionService::
 * hasOwnSignature) and arrives as `selfSigner`.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog, flush } = require('./helpers/dialog-harness')

const MINE = {
	sessionId: 'env_1',
	status: 'pending',
	kind: 'envelope',
	signerCount: 2,
	createdAt: 1785350000,
	cancellable: true,
	selfSigner: true,
}

const THEIRS = { ...MINE, sessionId: 'env_2', selfSigner: false }

function openStatus(harness, rows) {
	harness.stubFetch(harness.ok(rows))
	harness.openStatusDialog({ id: 7, name: 'Contrato.pdf' })
	return harness.window.document.querySelector('.signdocs-overlay')
}

test('a row you sign yourself offers to open your link', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [MINE])
	await flush()

	const btn = overlay.querySelector('.signdocs-request-sign')
	assert.notEqual(btn, null)
	assert.match(btn.textContent, /Assinar/)
})

test('a row you do not sign offers nothing', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [THEIRS])
	await flush()

	assert.equal(overlay.querySelector('.signdocs-request-sign'), null)
	// Cancelling is still available — this is only about the signing link.
	assert.notEqual(overlay.querySelector('.signdocs-request-cancel'), null)
})

test('clicking mints a fresh link and never renders it', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [MINE])
	await flush()

	const opened = []
	harness.window.open = (url) => {
		opened.push(url)
		return { location: '', close() {} }
	}

	const signingUrl = 'https://sign-hml.signdocs.test/s/ss_b?cs=ss_secret_fresh'
	harness.stubFetch(harness.ok({ url: signingUrl, expiresAt: '2026-08-15T12:00:00Z' }))

	const before = harness.fetchCalls.length
	overlay.querySelector('.signdocs-request-sign').click()
	await flush()

	const [url, init] = harness.fetchCalls[before]
	assert.match(url, /\/api\/v1\/sessions\/env_1\/own-link$/)
	assert.equal(init.method, 'POST')
	assert.equal(init.headers.requesttoken, 'test-request-token')

	// The credential is navigated to, never painted into the page.
	assert.equal(opened.length, 1)
	assert.equal(/ss_secret_fresh/.test(overlay.innerHTML), false)
})

test('the tab is opened in the click turn, before the request resolves', async () => {
	// Browsers only treat window.open as user-initiated inside the handler's
	// own turn; opening after the await is what the popup blocker eats.
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [MINE])
	await flush()

	const order = []
	harness.window.open = () => {
		order.push('open')
		return { location: '', close() {} }
	}
	harness.stubFetch(() => {
		order.push('fetch')
		return Promise.resolve({ ok: true, json: () => Promise.resolve({ url: 'https://x.test/s/1?cs=y' }) })
	})

	overlay.querySelector('.signdocs-request-sign').click()
	await flush()

	assert.deepEqual(order, ['open', 'fetch'])
})

test('a failure restores the button and reports it', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [MINE])
	await flush()

	let closed = false
	harness.window.open = () => ({ location: '', close() { closed = true } })
	harness.stubFetch(harness.fails('Session has expired'))

	const btn = overlay.querySelector('.signdocs-request-sign')
	btn.click()
	await flush()

	assert.equal(closed, true, 'the blank tab is not left open')
	assert.equal(btn.disabled, false)
	assert.match(btn.textContent, /Assinar/)
	assert.match(
		overlay.querySelector('.signdocs-status-message').textContent,
		/Não foi possível abrir seu link de assinatura/,
	)
})
