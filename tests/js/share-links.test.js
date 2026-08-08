/**
 * A signing link is only as strong as the policy behind it. Under simple
 * electronic signing the policy is a single click, so the URL is a bearer
 * credential: whoever holds it signs as the named signer. Offering the sender a
 * copy button next to it invites exactly that, so those links leave by SignDocs
 * email and nowhere else.
 *
 * lib/Service/SigningSessionService::mayShareLink decides this server-side and
 * simply never sends the URL; this suite covers the dialog that must not render
 * a control for one either way — including the anchor, since right-click →
 * "copy link address" recovers a URL just as well as the button does.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog, flush } = require('./helpers/dialog-harness')

const SIGNERS = [
	{ name: 'Maria Silva', email: 'maria@example.com', doc: '12345678909' },
	{ name: 'João Souza', email: 'joao@example.com', doc: '12345678909' },
]

/** Drive the dialog through review and send, landing on the result panel. */
async function sendReceiving(shareLinks, { userEmail = 'owner@example.com' } = {}) {
	const harness = loadDialog({ userEmail })
	const dialog = harness.openDialog()
	harness.stubFetch(harness.ok({ metadata: { shareLinks } }))

	dialog.fillSigners(SIGNERS)
	dialog.submit()
	dialog.clickConfirm()
	await flush()

	return dialog
}

const WITHHELD = { signerEmail: 'maria@example.com', shareable: false, inviteSent: true }
const SHARED = {
	signerEmail: 'joao@example.com',
	shareable: true,
	inviteSent: true,
	url: 'https://signdocs.test/s/2?cs=ss_secret_xyz',
}

test('a withheld link renders no anchor and no copy button', async () => {
	const dialog = await sendReceiving([WITHHELD])

	assert.equal(dialog.result.querySelectorAll('a').length, 0)
	assert.equal(dialog.result.querySelectorAll('[data-copy]').length, 0)
	assert.match(dialog.result.textContent, /maria@example\.com/)
	assert.match(dialog.result.textContent, /link enviado por email/)
})

test('a withheld link leaks no URL into the markup', async () => {
	const dialog = await sendReceiving([WITHHELD])

	// Not just the rendered text: an href or a data-copy attribute would hand
	// the URL back through the DOM.
	assert.equal(/signdocs\.test/.test(dialog.result.innerHTML), false)
	assert.equal(/cs=/.test(dialog.result.innerHTML), false)
})

test('a link with a second factor stays copyable', async () => {
	const dialog = await sendReceiving([SHARED])

	assert.equal(dialog.result.querySelectorAll('[data-copy]').length, 1)
	assert.equal(
		dialog.result.querySelector('a').getAttribute('href'),
		'https://signdocs.test/s/2?cs=ss_secret_xyz',
	)
})

test('a mixed envelope copies only the signers that carry a second factor', async () => {
	// The sender signing their own document is the one carve-out: SignDocs
	// emails them nothing, so their own link has to be shown.
	const dialog = await sendReceiving([WITHHELD, SHARED])

	assert.equal(dialog.result.querySelectorAll('[data-copy]').length, 1)
	assert.equal(dialog.result.querySelectorAll('a').length, 1)
	assert.equal(dialog.result.querySelectorAll('li').length, 2)
})

test('the explanation appears once, and only when something was withheld', async () => {
	const withheld = await sendReceiving([WITHHELD, { ...WITHHELD, signerEmail: 'ana@example.com' }])
	assert.equal(withheld.result.querySelectorAll('.signdocs-share-note').length, 1)
	assert.match(withheld.result.textContent, /não podem ser copiados aqui/)

	const shared = await sendReceiving([SHARED])
	assert.equal(shared.result.querySelectorAll('.signdocs-share-note').length, 0)
})

test('a missing URL is treated as withheld even without the flag', async () => {
	// Fail-closed: the control keys off the URL being there, so a response that
	// loses the flag can still never produce a link the server did not send.
	const dialog = await sendReceiving([{ signerEmail: 'maria@example.com', inviteSent: true }])

	assert.equal(dialog.result.querySelectorAll('[data-copy]').length, 0)
	assert.equal(dialog.result.querySelectorAll('a').length, 0)
})

test('signing your own document points at the link instead of promising an email', async () => {
	const dialog = await sendReceiving([{
		signerEmail: 'owner@example.com',
		shareable: true,
		inviteSent: null,
		url: 'https://signdocs.test/s/9?cs=ss_secret_own',
	}])

	assert.match(dialog.result.textContent, /Use o link abaixo para assinar/)
	assert.equal(dialog.result.querySelectorAll('[data-copy]').length, 1)
})

test('simple electronic signing is withdrawn when the profile has no email', () => {
	// With no owner the API emails nobody, and a click-only link is never shown,
	// so the combination would produce a document that can never be signed.
	const dialog = loadDialog().openDialog()

	assert.deepEqual(
		[...dialog.modeSelect.options].map((o) => o.value),
		['click_plus_otp', 'digital_certificate'],
	)
	assert.equal(dialog.modeSelect.value, 'click_plus_otp')

	const hint = dialog.overlay.querySelector('.signdocs-mode-noemail-hint')
	assert.equal(hint.hidden, false)
	assert.match(hint.textContent, /Defina um email no perfil/)
})

test('a profile with an email keeps every mode', () => {
	const dialog = loadDialog({ userEmail: 'owner@example.com' }).openDialog()

	assert.deepEqual(
		[...dialog.modeSelect.options].map((o) => o.value),
		['electronic', 'click_plus_otp', 'digital_certificate'],
	)
	assert.equal(dialog.overlay.querySelector('.signdocs-mode-noemail-hint').hidden, true)
})
