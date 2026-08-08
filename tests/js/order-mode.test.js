/**
 * Sequential order is bound to ICP-Brasil digital certificates: each signer
 * chains onto the previous signature, so the order is part of how the
 * signature is built rather than a scheduling preference. Every other mode is
 * parallel, and the dropdown must never offer sequential outside ICP mode.
 *
 * lib/Service/SigningSessionService::validateOptions enforces the same rule
 * server-side (see SigningSessionServiceHelpersTest); this suite covers the
 * dialog that is supposed to make the rejection unreachable.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog } = require('./helpers/dialog-harness')

/**
 * Every test here is about the mode/order coupling, not about who is sending.
 * A profile address keeps simple electronic signing on the dropdown — without
 * one the invite cannot be delivered and the option is dropped, which is
 * covered in share-links.test.js instead.
 */
const withEmail = () => loadDialog({ userEmail: 'owner@example.com' })

test('order dropdown offers only parallel outside ICP mode', () => {
	const dialog = withEmail().openDialog()

	assert.equal(dialog.modeSelect.value, 'electronic')
	assert.deepEqual(dialog.orderOptions(), ['parallel'])
	assert.equal(dialog.orderSelect.value, 'parallel')
	assert.match(dialog.orderHint.textContent, /apenas com Certificado Digital ICP-Brasil/)
})

test('order is derived from the mode, never chosen on its own', () => {
	const dialog = withEmail().openDialog()

	assert.equal(dialog.orderSelect.disabled, true)
	dialog.setMode('digital_certificate')
	assert.equal(dialog.orderSelect.disabled, true)
})

test('click + OTP does not unlock sequential either', () => {
	const dialog = withEmail().openDialog()

	dialog.setMode('click_plus_otp')
	assert.deepEqual(dialog.orderOptions(), ['parallel'])
	assert.equal(dialog.orderSelect.value, 'parallel')
})

test('selecting ICP adds sequential and selects it', () => {
	const dialog = withEmail().openDialog()

	dialog.setMode('digital_certificate')
	assert.deepEqual(dialog.orderOptions(), ['parallel', 'sequential'])
	assert.equal(dialog.orderSelect.value, 'sequential')
	assert.match(dialog.orderHint.textContent, /exige assinatura sequencial/)
})

test('reordering controls follow ICP mode', () => {
	const dialog = withEmail().openDialog()

	assert.equal(dialog.sequentialHint.hidden, true)
	assert.equal(dialog.signerList.classList.contains('signdocs-sequential'), false)

	dialog.setMode('digital_certificate')
	assert.equal(dialog.sequentialHint.hidden, false)
	assert.equal(dialog.signerList.classList.contains('signdocs-sequential'), true)
})

test('leaving ICP mode withdraws the sequential option', () => {
	const dialog = withEmail().openDialog()

	dialog.setMode('digital_certificate')
	dialog.setMode('electronic')

	assert.deepEqual(dialog.orderOptions(), ['parallel'])
	assert.equal(dialog.orderSelect.value, 'parallel')
	assert.equal(dialog.signerList.classList.contains('signdocs-sequential'), false)
	assert.match(dialog.orderHint.textContent, /apenas com Certificado Digital ICP-Brasil/)
})

test('repeated mode changes never duplicate the sequential option', () => {
	const dialog = withEmail().openDialog()

	for (let i = 0; i < 3; i++) {
		dialog.setMode('digital_certificate')
		dialog.setMode('click_plus_otp')
	}
	dialog.setMode('digital_certificate')

	assert.deepEqual(dialog.orderOptions(), ['parallel', 'sequential'])
})

test('the order the dialog sends matches the selected mode', async () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const dialog = harness.openDialog()
	harness.stubFetch(harness.ok({}))

	dialog.setMode('digital_certificate')
	dialog.fillSigners([
		{ name: 'Maria Silva', email: 'maria@example.com', doc: '12345678909' },
		{ name: 'João Souza', email: 'joao@example.com', doc: '12345678909' },
	])
	dialog.submit()
	dialog.clickConfirm()
	await require('./helpers/dialog-harness').flush()

	const body = JSON.parse(harness.fetchCalls[0][1].body)
	assert.equal(body.options.mode, 'digital_certificate')
	assert.equal(body.options.order, 'sequential')
})

test('a non-PDF offers only certificate signing', () => {
	// Interim gate: the API keeps non-PDF uploads as documentFormat=generic and
	// only the certificate step writes an artifact, so click/OTP would sign
	// something we could never hand back. The options are removed, not disabled.
	const dialog = withEmail().openDialog({ id: 7, name: 'Contrato.docx', mime: 'application/msword' })

	assert.deepEqual(
		[...dialog.modeSelect.options].map((o) => o.value),
		['digital_certificate'],
	)
	assert.equal(dialog.modeSelect.value, 'digital_certificate')
	assert.match(
		dialog.overlay.querySelector('.signdocs-mode-locked-hint').textContent,
		/só podem ser assinados com Certificado Digital/,
	)
	assert.equal(dialog.overlay.querySelector('.signdocs-mode-locked-hint').hidden, false)
})

test('gating a non-PDF also forces sequential order and reordering', () => {
	const dialog = withEmail().openDialog({ id: 7, name: 'Contrato.odt', mime: 'application/vnd.oasis.opendocument.text' })

	assert.deepEqual(dialog.orderOptions(), ['parallel', 'sequential'])
	assert.equal(dialog.orderSelect.value, 'sequential')
	assert.equal(dialog.signerList.classList.contains('signdocs-sequential'), true)
})

test('a PDF keeps every signing mode', () => {
	const dialog = withEmail().openDialog({ id: 5, name: 'Contrato.pdf', mime: 'application/pdf' })

	assert.deepEqual(
		[...dialog.modeSelect.options].map((o) => o.value),
		['electronic', 'click_plus_otp', 'digital_certificate'],
	)
	assert.equal(dialog.overlay.querySelector('.signdocs-mode-locked-hint').hidden, true)
})

test('the gate is case-insensitive on the extension', () => {
	const pdf = withEmail().openDialog({ id: 5, name: 'CONTRATO.PDF', mime: 'application/pdf' })
	assert.equal(pdf.modeSelect.options.length, 3)

	const docx = withEmail().openDialog({ id: 7, name: 'CONTRATO.DOCX', mime: 'application/msword' })
	assert.equal(docx.modeSelect.options.length, 1)
})
