/**
 * The signature-request list on the landing page — the only place in the GUI
 * where an in-flight request can be seen and stopped. Everything else in the
 * app creates requests; without this a send is irreversible from the UI.
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

test('the section stays hidden when there is nothing to show', async () => {
	const page = loadLanding({ responses: [[]] })
	await flush()

	assert.equal(page.section().hidden, true)
	assert.equal(page.rows().length, 0)
})

test('requests are listed with name, signers and status', async () => {
	const page = loadLanding({ responses: [[PENDING, SIGNED]] })
	await flush()

	assert.equal(page.section().hidden, false)
	assert.equal(page.rows().length, 2)

	const text = page.rows()[0].textContent
	assert.match(text, /Contrato\.pdf/)
	assert.match(text, /2 signatários/)
	assert.match(text, /Pendente/)
	assert.match(page.rows()[1].textContent, /Assinado/)
})

test('only cancellable requests offer a cancel button', async () => {
	const page = loadLanding({ responses: [[PENDING, SIGNED]] })
	await flush()

	assert.notEqual(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
	assert.equal(page.rowFor('ss_2').querySelector('.signdocs-request-cancel'), null)
})

test('cancelling asks first and sends nothing until confirmed', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()
	const callsAfterLoad = page.calls.length

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))

	const confirm = page.rowFor('env_1').querySelector('.signdocs-request-confirm')
	assert.notEqual(confirm, null, 'an inline confirmation appears')
	assert.match(confirm.textContent, /não poderão mais assinar/)
	assert.match(confirm.textContent, /já coletadas são preservadas/)
	assert.equal(page.calls.length, callsAfterLoad, 'nothing sent yet')
})

test('backing out of the confirmation cancels nothing', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue([PENDING])
	page.click('.signdocs-request-confirm-no', page.rowFor('env_1'))
	await flush()

	const posts = page.calls.filter((c) => c.init?.method === 'POST')
	assert.equal(posts.length, 0)
	assert.notEqual(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
})

test('confirming posts to the cancel endpoint and refreshes', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue(
		{ status: 200, body: { sessionId: 'env_1', status: 'cancelled', cancelledCount: 2, preservedSignedCount: 0 } },
		[{ ...PENDING, status: 'cancelled', cancellable: false }],
	)
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	const post = page.calls.find((c) => c.init?.method === 'POST')
	assert.notEqual(post, undefined, 'a POST was made')
	assert.match(post.url, /\/api\/v1\/sessions\/env_1\/cancel$/)
	assert.equal(post.init.headers.requesttoken, 'test-request-token')

	// The list re-renders from the server rather than being patched locally.
	assert.match(page.rowFor('env_1').textContent, /Cancelado/)
	assert.equal(page.rowFor('env_1').querySelector('.signdocs-request-cancel'), null)
})

test('preserved signatures are reported back to the user', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue(
		{ status: 200, body: { sessionId: 'env_1', status: 'cancelled', cancelledCount: 1, preservedSignedCount: 2 } },
		[],
	)
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	// Cancelling stops pending signers but never invalidates what was collected.
	assert.match(page.status().textContent, /2 assinatura\(s\) já coletada\(s\) foram preservadas/)
})

test('a failed cancel is surfaced, not swallowed', async () => {
	const page = loadLanding({ responses: [[PENDING]] })
	await flush()

	page.click('.signdocs-request-cancel', page.rowFor('env_1'))
	page.enqueue({ status: 409, body: { error: 'not_cancellable', status: 'completed' } }, [SIGNED])
	page.click('.signdocs-request-confirm-yes', page.rowFor('env_1'))
	await flush()

	assert.match(page.status().textContent, /Não foi possível cancelar/)
	assert.equal(page.status().hidden, false)
})

test('a deleted document still renders a usable row', async () => {
	const page = loadLanding({ responses: [[{ ...PENDING, fileName: null }]] })
	await flush()

	assert.match(page.rows()[0].textContent, /documento removido/)
	assert.notEqual(page.rows()[0].querySelector('.signdocs-request-cancel'), null)
})

test('file names are escaped, not interpreted', async () => {
	const page = loadLanding({ responses: [[{ ...PENDING, fileName: '<img src=x onerror=alert(1)>.pdf' }]] })
	await flush()

	assert.equal(page.rows()[0].querySelectorAll('img, script').length, 0)
	assert.match(page.rows()[0].textContent, /<img src=x onerror=alert\(1\)>\.pdf/)
})

test('the refresh button reloads the list', async () => {
	const page = loadLanding({ responses: [[]] })
	await flush()
	const before = page.calls.length

	page.enqueue([PENDING])
	page.click('.signdocs-requests-refresh')
	await flush()

	assert.equal(page.calls.length, before + 1)
	assert.equal(page.section().hidden, false)
})

test('a new request appears after the dialog reports a send', async () => {
	const page = loadLanding({ responses: [[]] })
	await flush()
	assert.equal(page.section().hidden, true)

	page.enqueue([PENDING])
	page.window.dispatchEvent(new page.window.CustomEvent('signdocs:session-created'))
	await flush()

	assert.equal(page.section().hidden, false)
	assert.equal(page.rows().length, 1)
})
