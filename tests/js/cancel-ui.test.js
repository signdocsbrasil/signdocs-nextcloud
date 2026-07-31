/**
 * The signature-request view on the landing page — the only place in the GUI
 * where an in-flight request can be seen and stopped.
 *
 * It is a separate view behind a fourth entry point rather than a section on
 * the home screen, so a user with several requests doesn't have the creation
 * flow pushed below the fold.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadLanding, flush } = require('./helpers/landing-harness')

const PENDING = {
	sessionId: 'env_1',
	fileId: 7,
	fileName: 'Contrato.pdf',
	status: 'pending',
	kind: 'envelope',
	signerCount: 2,
	createdAt: 1785350000,
	cancellable: true,
}
const SIGNED = {
	sessionId: 'ss_2',
	fileId: 8,
	fileName: 'Recibo.pdf',
	status: 'completed',
	kind: 'session',
	signerCount: 1,
	createdAt: 1785340000,
	cancellable: false,
}

/** Open the request view and settle the initial load. */
async function openList(page) {
	page.openRequests()
	await flush()
}

test('the home screen shows the entry points, not the list', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()

	assert.equal(page.view('home').hidden, false)
	assert.equal(page.view('requests').hidden, true)
	// Nothing is fetched until the list is actually opened.
	assert.equal(page.calls.length, 0)
})

test('the fourth entry point opens the list', async () => {
	const page = loadLanding()
	page.enqueue([PENDING, SIGNED])
	await openList(page)

	assert.equal(page.view('home').hidden, true)
	assert.equal(page.view('requests').hidden, false)
	assert.equal(page.rows().length, 2)
})

test('back returns to the entry points', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-requests-back')

	assert.equal(page.view('home').hidden, false)
	assert.equal(page.view('requests').hidden, true)
})

test('requests are listed with name, signers and status', async () => {
	const page = loadLanding()
	page.enqueue([PENDING, SIGNED])
	await openList(page)

	const text = page.rows()[0].textContent
	assert.match(text, /Contrato\.pdf/)
	assert.match(text, /2 signatários/)
	assert.match(text, /Pendente/)
	assert.match(page.rows()[1].textContent, /Assinado/)
})

test('an empty list says so instead of showing a blank view', async () => {
	const page = loadLanding()
	page.enqueue([])
	await openList(page)

	assert.equal(page.view('requests').hidden, false)
	assert.equal(page.empty().hidden, false)
	assert.equal(page.rows().length, 0)
})

test('only cancellable requests offer a cancel button', async () => {
	const page = loadLanding()
	page.enqueue([PENDING, SIGNED])
	await openList(page)

	assert.notEqual(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
	assert.equal(page.rowFor('ss_2').querySelector('.signdocs-request-cancel'), null)
})

test('cancelling asks first and sends nothing until confirmed', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)
	const before = page.calls.length

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))

	const confirm = page.rowFor('env_1').querySelector('.signdocs-request-confirm')
	assert.notEqual(confirm, null)
	assert.match(confirm.textContent, /não poderão mais assinar/)
	assert.match(confirm.textContent, /já coletadas são preservadas/)
	assert.equal(page.calls.length, before, 'nothing sent yet')
})

test('backing out of the confirmation cancels nothing', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue([PENDING])
	page.click('.signdocs-request-confirm-no', page.rowFor('env_1'))
	await flush()

	assert.equal(page.calls.filter((c) => c.init?.method === 'POST').length, 0)
	assert.notEqual(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
})

test('confirming posts to the cancel endpoint and refreshes', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue(
		{ status: 200, body: { sessionId: 'env_1', status: 'cancelled', cancelledCount: 2, preservedSignedCount: 0 } },
		[{ ...PENDING, status: 'cancelled', cancellable: false }],
	)
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	const post = page.calls.find((c) => c.init?.method === 'POST')
	assert.notEqual(post, undefined)
	assert.match(post.url, /\/api\/v1\/sessions\/env_1\/cancel$/)
	assert.equal(post.init.headers.requesttoken, 'test-request-token')
	assert.match(page.rowFor('env_1').textContent, /Cancelado/)
	assert.equal(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
})

test('preserved signatures are reported back to the user', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue(
		{ status: 200, body: { sessionId: 'env_1', status: 'cancelled', cancelledCount: 1, preservedSignedCount: 2 } },
		[],
	)
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	assert.match(page.status().textContent, /2 assinatura\(s\) já coletada\(s\) foram preservadas/)
})

test('a failed cancel reports in the row and offers a retry', async () => {
	// Observed live: an API token request timed out, the cancel 500'd, and the
	// row silently stayed "Pendente" because the only feedback was a page-level
	// message far down the page. The failure has to be visible at the row.
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue({ status: 500, body: { error: 'cancel_failed', message: 'Token request failed' } })
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	const row = page.rowFor('env_1')
	assert.match(row.textContent, /Não foi possível cancelar/)
	assert.match(row.textContent, /Token request failed/)
	assert.notEqual(row.querySelector('.signdocs-request-retry'), null, 'a retry is offered')
	// Still pending, and honest about it.
	assert.match(row.textContent, /Pendente/)
})

test('the retry re-posts the cancel', async () => {
	const page = loadLanding()
	page.enqueue([PENDING])
	await openList(page)

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue({ status: 500, body: { error: 'cancel_failed', message: 'timeout' } })
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	const before = page.calls.filter((c) => c.init?.method === 'POST').length
	page.enqueue(
		{ status: 200, body: { sessionId: 'env_1', status: 'cancelled', cancelledCount: 1, preservedSignedCount: 0 } },
		[{ ...PENDING, status: 'cancelled', cancellable: false }],
	)
	page.click('.signdocs-request-retry', page.rowFor('env_1'))
	await flush()

	assert.equal(page.calls.filter((c) => c.init?.method === 'POST').length, before + 1)
	assert.match(page.rowFor('env_1').textContent, /Cancelado/)
})

test('a deleted document still renders a usable row', async () => {
	const page = loadLanding()
	page.enqueue([{ ...PENDING, fileName: null }])
	await openList(page)

	assert.match(page.rows()[0].textContent, /documento removido/)
	assert.notEqual(page.rows()[0].querySelector('.signdocs-request-cancel'), null)
})

test('file names are escaped, not interpreted', async () => {
	const page = loadLanding()
	page.enqueue([{ ...PENDING, fileName: '<img src=x onerror=alert(1)>.pdf' }])
	await openList(page)

	assert.equal(page.rows()[0].querySelectorAll('img, script').length, 0)
	assert.match(page.rows()[0].textContent, /<img src=x onerror=alert\(1\)>\.pdf/)
})

test('the refresh button reloads the list', async () => {
	const page = loadLanding()
	page.enqueue([])
	await openList(page)
	const before = page.calls.length

	page.enqueue([PENDING])
	page.click('.signdocs-requests-refresh')
	await flush()

	assert.equal(page.calls.length, before + 1)
	assert.equal(page.rows().length, 1)
})

test('a new request is picked up after the dialog reports a send', async () => {
	const page = loadLanding()
	page.enqueue([])
	await openList(page)
	assert.equal(page.rows().length, 0)

	page.enqueue([PENDING])
	page.window.dispatchEvent(new page.window.CustomEvent('signdocs:session-created'))
	await flush()

	assert.equal(page.rows().length, 1)
})
