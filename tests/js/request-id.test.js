/**
 * Every submission carries one request id, and every attempt at that submission
 * carries the same one.
 *
 * The confirm button can send more than once — a double click, or a retry after
 * the request times out. Without a stable id each attempt mints a fresh
 * idempotency key upstream and so buys another envelope, another quota charge
 * and another round of invitations to the same people.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog, flush } = require('./helpers/dialog-harness')

const SIGNERS = [
	{ name: 'Maria Silva', email: 'maria@example.com', doc: '12345678909' },
	{ name: 'Empresa ABC Ltda', email: 'fiscal@abc.com.br', doc: '12345678000195' },
]

function sentOptions(harness, index = 0) {
	const [, init] = harness.fetchCalls[index]
	return JSON.parse(init.body).options
}

function reviewing() {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const dialog = harness.openDialog()
	dialog.fillSigners(SIGNERS)
	dialog.submit()
	return { harness, dialog }
}

test('the submission sends a request id', async () => {
	const { harness, dialog } = reviewing()
	dialog.clickConfirm()
	await flush()

	const { requestId } = sentOptions(harness)
	assert.equal(typeof requestId, 'string')
	// Bounded and separator-free: the server rejects anything else, and `#` is
	// what it appends the per-signer suffix with.
	assert.match(requestId, /^[A-Za-z0-9._-]{8,128}$/)
})

test('a second attempt at the same submission reuses the id', async () => {
	const { harness, dialog } = reviewing()

	dialog.clickConfirm()
	await flush()
	dialog.clickConfirm()
	await flush()

	assert.equal(harness.fetchCalls.length, 2, 'both attempts were sent')
	assert.equal(
		sentOptions(harness, 0).requestId,
		sentOptions(harness, 1).requestId,
		'the retry must land on the cached response, not create a second envelope',
	)
})

test('a fresh submission mints a new id', async () => {
	const first = reviewing()
	first.dialog.clickConfirm()
	await flush()

	const second = reviewing()
	second.dialog.clickConfirm()
	await flush()

	assert.notEqual(
		sentOptions(first.harness).requestId,
		sentOptions(second.harness).requestId,
		'sending the same document again on purpose must still work',
	)
})
