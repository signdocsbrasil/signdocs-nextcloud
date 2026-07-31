/**
 * Per-document status panel, opened from the Files context menu.
 *
 * The landing-page list covers every request; this covers the one document in
 * front of the user, which is where they are when they wonder about its state.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog, flush } = require('./helpers/dialog-harness')

const PENDING = {
	sessionId: 'env_1',
	status: 'pending',
	kind: 'envelope',
	signerCount: 2,
	createdAt: 1785350000,
	cancellable: true,
}

function openStatus(harness, rows) {
	harness.stubFetch(harness.ok(rows))
	harness.openStatusDialog({ id: 7, name: 'Contrato.pdf' })
	return harness.window.document.querySelector('.signdocs-overlay')
}

test('lists the requests for the document', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const overlay = openStatus(harness, [PENDING])
	await flush()

	assert.match(overlay.querySelector('.signdocs-file-name').textContent, /Contrato\.pdf/)
	const rows = overlay.querySelectorAll('.signdocs-request')
	assert.equal(rows.length, 1)
	assert.match(rows[0].textContent, /Pendente/)
	assert.match(rows[0].textContent, /2 signatários/)
})

test('says so when the document has never been sent', async () => {
	const harness = loadDialog()
	const overlay = openStatus(harness, [])
	await flush()

	assert.match(
		overlay.querySelector('.signdocs-status-message').textContent,
		/Nenhuma solicitação de assinatura para este documento/,
	)
	assert.equal(overlay.querySelectorAll('.signdocs-request').length, 0)
})

test('the row does not repeat the file name the header already shows', async () => {
	const harness = loadDialog()
	const overlay = openStatus(harness, [PENDING])
	await flush()

	assert.equal(overlay.querySelector('.signdocs-request-name'), null)
})

test('cancelling from the panel asks first, then posts', async () => {
	const harness = loadDialog()
	const overlay = openStatus(harness, [PENDING])
	await flush()

	overlay.querySelector('.signdocs-request-cancel').click()
	const confirm = overlay.querySelector('.signdocs-request-confirm')
	assert.notEqual(confirm, null)
	assert.match(confirm.textContent, /já coletadas são preservadas/)

	const before = harness.fetchCalls.length
	let call = 0
	harness.stubFetch(() => {
		call++
		const body = call === 1
			? { sessionId: 'env_1', status: 'cancelled', cancelledCount: 2, preservedSignedCount: 0 }
			: [{ ...PENDING, status: 'cancelled', cancellable: false }]
		return Promise.resolve({ ok: true, json: () => Promise.resolve(body) })
	})
	overlay.querySelector('.signdocs-request-confirm-yes').click()
	await flush()

	const post = harness.fetchCalls.slice(before).find((c) => c[1]?.method === 'POST')
	assert.notEqual(post, undefined)
	assert.match(post[0], /\/api\/v1\/sessions\/env_1\/cancel$/)
	// Re-rendered from the server rather than patched in place.
	assert.match(overlay.querySelector('.signdocs-request').textContent, /Cancelado/)
})

test('a load failure is reported instead of showing an empty panel', async () => {
	const harness = loadDialog()
	harness.stubFetch(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }))
	harness.openStatusDialog({ id: 7, name: 'Contrato.pdf' })
	await flush()

	const overlay = harness.window.document.querySelector('.signdocs-overlay')
	assert.match(
		overlay.querySelector('.signdocs-status-message').textContent,
		/Não foi possível carregar/,
	)
})
