/**
 * The review step: nothing reaches SignDocs until the user confirms what the
 * signers and the signature type actually are.
 */
const test = require('node:test')
const assert = require('node:assert/strict')
const { loadDialog, flush } = require('./helpers/dialog-harness')

const TWO_SIGNERS = [
	{ name: 'Maria Silva', email: 'maria@example.com', doc: '12345678909' },
	{ name: 'Empresa ABC Ltda', email: 'fiscal@abc.com.br', doc: '12345678000195' },
]

function reviewing(rows = TWO_SIGNERS, options = {}) {
	const harness = loadDialog({ userEmail: 'owner@example.com', ...options })
	const dialog = harness.openDialog(options.fileInfo)
	if (options.mode) dialog.setMode(options.mode)
	dialog.fillSigners(rows)
	dialog.submit()
	return { harness, dialog }
}

test('submitting the form reviews instead of sending', () => {
	const { harness, dialog } = reviewing()

	assert.equal(harness.fetchCalls.length, 0, 'no request before confirmation')
	assert.equal(dialog.form.hidden, true)
	assert.equal(dialog.confirmPanel.hidden, false)
})

test('review lists the document, every signer, and the signature type', () => {
	const { dialog } = reviewing()
	const text = dialog.confirmText()

	assert.match(text, /contrato\.pdf/)
	assert.match(text, /Maria Silva/)
	assert.match(text, /Empresa ABC Ltda/)
	assert.match(text, /maria@example\.com/)
	assert.match(text, /Paralela/)
	assert.equal(dialog.confirmSigners().length, 2)
})

test('fiscal ids are punctuated for review', () => {
	const { dialog } = reviewing()
	const text = dialog.confirmText()

	assert.match(text, /123\.456\.789-09/, 'CPF')
	assert.match(text, /12\.345\.678\/0001-95/, 'CNPJ')
})

test('signers are numbered only when the order is sequential', () => {
	const parallel = reviewing().dialog
	assert.equal(
		parallel.overlay.querySelector('.signdocs-confirm-signer-list')
			.classList.contains('signdocs-numbered'),
		false,
	)

	const sequential = reviewing(TWO_SIGNERS, { mode: 'digital_certificate' }).dialog
	assert.equal(
		sequential.overlay.querySelector('.signdocs-confirm-signer-list')
			.classList.contains('signdocs-numbered'),
		true,
	)
	assert.match(sequential.confirmText(), /Sequencial/)
})

test('ICP signature type distinguishes PAdES from CAdES', () => {
	const pdf = reviewing(TWO_SIGNERS, { mode: 'digital_certificate' }).dialog
	assert.match(pdf.confirmText(), /PAdES/)

	const docx = reviewing(TWO_SIGNERS, {
		mode: 'digital_certificate',
		fileInfo: { id: 8, name: 'contrato.docx', mime: 'application/msword' },
	}).dialog
	assert.match(docx.confirmText(), /CAdES/)
})

test('going back keeps every field filled in', () => {
	const { dialog } = reviewing()

	dialog.clickBack()

	assert.equal(dialog.form.hidden, false)
	assert.equal(dialog.confirmPanel.hidden, true)
	assert.equal(dialog.overlay.querySelector('input[name=name]').value, 'Maria Silva')
	assert.equal(dialog.overlay.querySelector('input[name=email]').value, 'maria@example.com')
})

test('an invalid fiscal id never reaches the review step', () => {
	const harness = loadDialog({ userEmail: 'owner@example.com' })
	const dialog = harness.openDialog()

	dialog.fillSigners([{ name: 'Maria Silva', email: 'maria@example.com', doc: '11111111111' }])
	dialog.submit()

	assert.equal(dialog.confirmPanel.hidden, true, 'stayed on the form')
	assert.equal(harness.fetchCalls.length, 0)
	assert.match(harness.alerts.at(-1), /CPF inválido para Maria Silva/)
})

test('confirming posts the reviewed payload', async () => {
	const { harness, dialog } = reviewing()
	harness.stubFetch(harness.ok({ metadata: { shareLinks: [] } }))

	dialog.clickConfirm()
	await flush()

	assert.equal(harness.fetchCalls.length, 1)
	const [url, init] = harness.fetchCalls[0]
	assert.match(url, /\/apps\/signdocs_brasil\/api\/v1\/sessions$/)
	assert.equal(init.method, 'POST')
	assert.equal(init.headers.requesttoken, 'test-request-token')

	const body = JSON.parse(init.body)
	assert.equal(body.fileId, 42)
	assert.equal(body.signers.length, 2)
	assert.equal(body.signers[0].cpf, '12345678909')
	assert.equal(body.signers[1].cnpj, '12345678000195')
	assert.equal(body.options.order, 'parallel')
})

test('a failed send keeps the review step so the work is not lost', async () => {
	const { harness, dialog } = reviewing()
	harness.stubFetch(harness.fails('quota excedida'))

	dialog.clickConfirm()
	await flush()

	assert.equal(dialog.confirmPanel.hidden, false)
	assert.match(harness.alerts.at(-1), /quota excedida/)
	assert.equal(dialog.overlay.querySelector('.signdocs-confirm-send').disabled, false)
	assert.equal(dialog.result.hidden, true)
})

test('a successful send replaces the review with the share links', async () => {
	const { harness, dialog } = reviewing()
	harness.stubFetch(harness.ok({
		metadata: {
			shareLinks: [{ signerEmail: 'maria@example.com', url: 'https://signdocs.test/s/1?cs=abc' }],
		},
	}))

	dialog.clickConfirm()
	await flush()

	assert.equal(dialog.confirmPanel.hidden, true)
	assert.equal(dialog.result.hidden, false)
	assert.match(dialog.result.textContent, /maria@example\.com/)
})

test('signer names and file names are escaped, not interpreted', () => {
	const { dialog } = reviewing(
		[{ name: '<script>alert(1)</script>', email: 'a@b.com', doc: '12345678909' }],
		{ fileInfo: { id: 9, name: '<img src=x onerror=alert(1)>.pdf', mime: 'application/pdf' } },
	)

	assert.equal(dialog.confirmPanel.querySelectorAll('script, img').length, 0)
	assert.match(dialog.confirmText(), /<script>alert\(1\)<\/script>/)
})

test('review promises invites when the profile has an email', () => {
	const { dialog } = reviewing()

	assert.match(dialog.confirmText(), /Convites por email serão enviados aos 2 signatários/)
	assert.equal(dialog.confirmPanel.querySelector('.signdocs-confirm-warning'), null)
})

test('a single signer is told about their own link, not a signer count', () => {
	const { dialog } = reviewing([TWO_SIGNERS[0]])

	assert.match(dialog.confirmText(), /O signatário receberá por email o link para assinar/)
})

test('review warns instead of promising invites with no profile email', () => {
	// No `userEmail` initial state → no owner on the request → SignDocs emails
	// nobody, and the sender has to hand out the links themselves.
	const harness = loadDialog()
	const dialog = harness.openDialog()

	dialog.fillSigners(TWO_SIGNERS)
	dialog.submit()

	assert.match(dialog.confirmText(), /nenhum convite será enviado automaticamente/)
	assert.notEqual(dialog.confirmPanel.querySelector('.signdocs-confirm-warning'), null)
})

test('a non-PDF can only reach review as a certificate signature', () => {
	// The mode is gated in the form, so by the time we review a .docx the only
	// possible policy is the certificate one — and the review says so.
	const { dialog } = reviewing(TWO_SIGNERS, {
		fileInfo: { id: 7, name: 'Contrato.docx', mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
	})

	assert.match(dialog.confirmText(), /Certificado ICP-Brasil \(CAdES/)
	assert.match(dialog.confirmText(), /Sequencial/)
})

test('a signer who is the sender is flagged as not getting an invite', () => {
	const { dialog } = reviewing([
		{ name: 'Eu Mesmo', email: 'OWNER@example.com', doc: '12345678909' },
		{ name: 'Maria Silva', email: 'maria@example.com', doc: '12345678909' },
	])

	const notes = dialog.confirmPanel.querySelectorAll('.signdocs-confirm-signer-note')
	assert.equal(notes.length, 1, 'only the sender row is flagged, case-insensitively')
	assert.match(notes[0].textContent, /é o seu próprio email/)
	assert.match(dialog.confirmSigners()[0].textContent, /Eu Mesmo/)
})
