/**
 * SignDocs Brasil — Files action.
 *
 * Registers a "Assinar com SignDocs" action against the Nextcloud Files app
 * via the modern @nextcloud/files registry. NC 28+ removed the global
 * OCA.Files.fileActions registry, so a bundled entry point is required.
 *
 * Front-end has no direct knowledge of SignDocs API — it only POSTs to the
 * app's PHP backend, which proxies to SignDocs via the PHP SDK and holds the
 * tenant credentials.
 */
import { FileAction, registerFileAction, Permission } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	escapeHtml,
	fetchRequestsForFile,
	renderRequests,
} from './signing-requests'

const APP_ID = 'signdocs_brasil'

const initialState = loadState(APP_ID, 'signdocs', {
	apiBase: '/apps/' + APP_ID + '/api/v1',
	supportedMimeTypes: [
		'application/pdf',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
	],
})

const supportedMimeTypes = new Set(initialState.supportedMimeTypes || [])

// The signing dialog is also opened from the landing page, which publishes its
// own initial-state key. Read both so the confirmation step knows the NC user's
// email on either entry surface.
const landingState = loadState(APP_ID, 'signdocs_landing', {})

// SignDocs only auto-dispatches the invite emails when the create request
// carries an owner, and the API skips the invite for a signer whose address
// matches that owner's (they get the link back in the response instead). The
// owner is the NC user's profile email — absent, nothing is emailed. See
// SigningSessionService::create. The confirmation step reads this so it states
// what will actually happen rather than promising invites we know won't go out.
const ownerEmail = String(initialState.userEmail || landingState.userEmail || '').trim()

const STATUS_ICON = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
</svg>
`.trim()

const SIGN_ICON = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path fill="currentColor" d="M3 17v3h3l11-11-3-3L3 17zm17.7-11.3a1 1 0 0 0 0-1.4l-2-2a1 1 0 0 0-1.4 0l-2 2 3.4 3.4 2-2z"/>
</svg>
`.trim()

// CPF / CNPJ check-digit validation. Mirrors lib/Service/CpfCnpjValidator.php
// so the front-end gives instant feedback without a round-trip, while the
// PHP side stays the authoritative gate.
function isValidCpf(digits) {
	if (digits.length !== 11 || !/^\d+$/.test(digits)) return false
	if (/^(\d)\1{10}$/.test(digits)) return false
	const calc = (slice, startWeight) => {
		let sum = 0
		for (let i = 0; i < slice.length; i++) {
			sum += parseInt(slice[i], 10) * (startWeight - i)
		}
		const r = sum % 11
		return r < 2 ? 0 : 11 - r
	}
	if (calc(digits.slice(0, 9), 10) !== parseInt(digits[9], 10)) return false
	if (calc(digits.slice(0, 10), 11) !== parseInt(digits[10], 10)) return false
	return true
}

function isValidCnpj(digits) {
	if (digits.length !== 14 || !/^\d+$/.test(digits)) return false
	if (/^(\d)\1{13}$/.test(digits)) return false
	const calc = (slice, weights) => {
		let sum = 0
		for (let i = 0; i < slice.length; i++) {
			sum += parseInt(slice[i], 10) * weights[i]
		}
		const r = sum % 11
		return r < 2 ? 0 : 11 - r
	}
	const w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
	const w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
	if (calc(digits.slice(0, 12), w1) !== parseInt(digits[12], 10)) return false
	if (calc(digits.slice(0, 13), w2) !== parseInt(digits[13], 10)) return false
	return true
}

// Punctuate digits-only documents for the review step: CPF as 123.456.789-00,
// CNPJ as 12.345.678/0001-00. Input is already validated by this point.
function formatCpfCnpj(digits) {
	const d = String(digits ?? '').replace(/\D+/g, '')
	if (d.length === 11) {
		return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`
	}
	if (d.length === 14) {
		return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`
	}
	return d
}

function modeLabel(mode, fileName) {
	if (mode === 'click_plus_otp') return t(APP_ID, 'Clique + OTP por email')
	if (mode === 'digital_certificate') {
		// PAdES (signature embedded in the PDF) for .pdf; CAdES (detached
		// .p7s alongside the original) for everything else.
		return /\.pdf$/i.test(fileName)
			? t(APP_ID, 'Certificado ICP-Brasil (PAdES, embutido no PDF)')
			: t(APP_ID, 'Certificado ICP-Brasil (CAdES, .p7s separado)')
	}
	return t(APP_ID, 'Eletrônica simples (clique)')
}

function orderLabel(order) {
	return order === 'sequential'
		? t(APP_ID, 'Sequencial')
		: t(APP_ID, 'Paralela (qualquer ordem)')
}


function addSignerRow(container) {
	const row = document.createElement('div')
	row.className = 'signdocs-signer-row'
	// draggable is toggled with the parent's .sequential class — see
	// applySequentialDragState. The grip handle is the *only* drag origin
	// (the row itself ignores drags on its inputs/buttons) so users can
	// still select text inside the email/cpf fields normally.
	row.innerHTML = `
		<span class="signdocs-drag-handle" aria-hidden="true" title="${t(APP_ID, 'Arrastar para reordenar')}">⋮⋮</span>
		<span class="signdocs-signer-index" aria-hidden="true"></span>
		<input type="text" name="name" placeholder="${t(APP_ID, 'Nome / Razão Social')}" required />
		<input type="email" name="email" placeholder="${t(APP_ID, 'Email')}" required />
		<input type="text" name="cpf_cnpj" placeholder="${t(APP_ID, 'CPF / CNPJ')}" inputmode="numeric" required />
		<button type="button" class="signdocs-move-up" aria-label="${t(APP_ID, 'Mover para cima')}" tabindex="0">▲</button>
		<button type="button" class="signdocs-move-down" aria-label="${t(APP_ID, 'Mover para baixo')}" tabindex="0">▼</button>
		<button type="button" class="signdocs-remove-signer" aria-label="${t(APP_ID, 'Remover')}">×</button>
	`
	row.querySelector('.signdocs-remove-signer').addEventListener('click', () => {
		row.remove()
		renumberSigners(container)
	})
	row.querySelector('.signdocs-move-up').addEventListener('click', () => moveRow(row, -1))
	row.querySelector('.signdocs-move-down').addEventListener('click', () => moveRow(row, +1))

	const handle = row.querySelector('.signdocs-drag-handle')
	// Only the handle initiates a drag — onmousedown sets draggable=true on
	// the row, mouseup/dragend revert. Without this, clicking a row's input
	// can accidentally start a drag in some browsers.
	handle.addEventListener('mousedown', () => { row.draggable = true })
	handle.addEventListener('mouseup', () => { row.draggable = false })
	row.addEventListener('dragstart', (e) => {
		if (!container.classList.contains('signdocs-sequential')) {
			e.preventDefault()
			return
		}
		row.classList.add('signdocs-dragging')
		e.dataTransfer.effectAllowed = 'move'
		// Required for Firefox to actually start the drag.
		e.dataTransfer.setData('text/plain', '')
	})
	row.addEventListener('dragend', () => {
		row.classList.remove('signdocs-dragging')
		row.draggable = false
		renumberSigners(container)
	})

	container.appendChild(row)
	renumberSigners(container)
}

function moveRow(row, delta) {
	const sibling = delta < 0 ? row.previousElementSibling : row.nextElementSibling
	if (!sibling || !sibling.classList.contains('signdocs-signer-row')) return
	const container = row.parentElement
	if (delta < 0) container.insertBefore(row, sibling)
	else container.insertBefore(sibling, row)
	renumberSigners(container)
}

function renumberSigners(container) {
	const rows = container.querySelectorAll('.signdocs-signer-row')
	rows.forEach((row, i) => {
		row.querySelector('.signdocs-signer-index').textContent = String(i + 1) + '.'
		// Disable up/down at the boundaries so keyboard users get visual feedback.
		row.querySelector('.signdocs-move-up').disabled = i === 0
		row.querySelector('.signdocs-move-down').disabled = i === rows.length - 1
	})
}

function setupSignerListDragAndDrop(container) {
	container.addEventListener('dragover', (e) => {
		const dragging = container.querySelector('.signdocs-signer-row.signdocs-dragging')
		if (!dragging) return
		e.preventDefault()
		e.dataTransfer.dropEffect = 'move'
		const afterRow = getRowAfterY(container, e.clientY)
		if (afterRow == null) {
			container.appendChild(dragging)
		} else if (afterRow !== dragging.nextElementSibling) {
			container.insertBefore(dragging, afterRow)
		}
	})
}

function getRowAfterY(container, y) {
	const candidates = [...container.querySelectorAll('.signdocs-signer-row:not(.signdocs-dragging)')]
	return candidates.find((row) => {
		const rect = row.getBoundingClientRect()
		return y < rect.top + rect.height / 2
	}) || null
}

/**
 * Review step. Nothing has been sent to SignDocs at this point — the form is
 * only hidden, so "Voltar" restores it with every field still filled in.
 * Mirrors the Dropbox extension's "Confirmar envio" card.
 */
function renderConfirmStep(overlay, ctx, onConfirm) {
	const { fileName, signers, mode, order } = ctx
	const form = overlay.querySelector('form')
	const confirm = overlay.querySelector('.signdocs-confirm')
	const isSequential = order === 'sequential'
	const isEnvelope = signers.length > 1

	let inviteLead
	if (!ownerEmail) {
		// No owner on the request → the API emails nobody. Say so up front
		// instead of letting the user think invites went out.
		inviteLead = t(APP_ID, 'Seu perfil do Nextcloud não tem email, então nenhum convite será enviado automaticamente. Você receberá os links para compartilhar com cada signatário.')
	} else if (isEnvelope) {
		inviteLead = t(APP_ID, 'Convites por email serão enviados aos %n signatários abaixo.').replace('%n', String(signers.length))
	} else {
		inviteLead = t(APP_ID, 'O signatário receberá por email o link para assinar.')
	}

	const isOwnAddress = (email) => ownerEmail !== ''
		&& String(email).toLowerCase() === ownerEmail.toLowerCase()

	confirm.innerHTML = `
		<h3>${t(APP_ID, 'Confirmar envio')}</h3>
		<p class="signdocs-confirm-lead ${ownerEmail ? '' : 'signdocs-confirm-warning'}">
			${escapeHtml(inviteLead)}
		</p>

		<dl class="signdocs-confirm-summary">
			<dt>${t(APP_ID, 'Documento')}</dt>
			<dd>${escapeHtml(fileName)}</dd>
			<dt>${t(APP_ID, 'Tipo de assinatura')}</dt>
			<dd class="signdocs-confirm-highlight">${escapeHtml(modeLabel(mode, fileName))}</dd>
			<dt>${t(APP_ID, 'Ordem')}</dt>
			<dd>${escapeHtml(orderLabel(order))}</dd>
		</dl>


		<div class="signdocs-confirm-signers">
			<span class="signdocs-confirm-signers-title">
				${isEnvelope
					? t(APP_ID, 'Signatários (%n)').replace('%n', String(signers.length))
					: t(APP_ID, 'Signatário')}
			</span>
			<ol class="signdocs-confirm-signer-list ${isSequential ? 'signdocs-numbered' : ''}">
				${signers.map((s) => `
					<li>
						<span class="signdocs-confirm-signer-name">${escapeHtml(s.name)}</span>
						<span class="signdocs-confirm-signer-email">${escapeHtml(s.email)}</span>
						<span class="signdocs-confirm-signer-doc">
							${s.cpf ? 'CPF' : 'CNPJ'} ${escapeHtml(formatCpfCnpj(s.cpf || s.cnpj))}
						</span>
						${isOwnAddress(s.email)
							? `<span class="signdocs-confirm-signer-note">${t(APP_ID, 'Convite não enviado — é o seu próprio email. Use o link exibido após o envio.')}</span>`
							: ''}
					</li>
				`).join('')}
			</ol>
			${isSequential
				? `<p class="signdocs-sequential-hint">${t(APP_ID, 'Os signatários assinarão nesta ordem.')}</p>`
				: ''}
		</div>

		<footer>
			<button type="button" class="signdocs-confirm-back">← ${t(APP_ID, 'Voltar')}</button>
			<button type="button" class="signdocs-confirm-send primary">${t(APP_ID, 'Confirmar e enviar')}</button>
		</footer>
	`

	form.hidden = true
	confirm.hidden = false

	const sendBtn = confirm.querySelector('.signdocs-confirm-send')
	const backBtn = confirm.querySelector('.signdocs-confirm-back')

	backBtn.addEventListener('click', () => {
		confirm.hidden = true
		form.hidden = false
	})

	sendBtn.addEventListener('click', async () => {
		sendBtn.disabled = true
		backBtn.disabled = true
		sendBtn.textContent = t(APP_ID, 'Enviando…')
		try {
			await onConfirm()
		} catch (err) {
			// Stay on the review step so the user can retry without
			// re-typing every signer.
			sendBtn.disabled = false
			backBtn.disabled = false
			sendBtn.textContent = t(APP_ID, 'Confirmar e enviar')
			alert(t(APP_ID, 'Erro: ') + err.message)
		}
	})
}

function renderResult(overlay, data) {
	const form = overlay.querySelector('form')
	const confirm = overlay.querySelector('.signdocs-confirm')
	const result = overlay.querySelector('.signdocs-result')
	form.hidden = true
	confirm.hidden = true
	result.hidden = false
	const links = data?.metadata?.shareLinks || []
	result.innerHTML = `
		<h3>${t(APP_ID, 'Enviado!')}</h3>
		<p>${t(APP_ID, 'Cada signatário receberá um link para assinar.')}</p>
		<ul class="signdocs-share-links">
			${links.map((l) => `
				<li>
					<strong>${escapeHtml(l.signerEmail || '')}</strong>:
					<a href="${escapeHtml(l.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(l.url)}</a>
					<button type="button" data-copy="${escapeHtml(l.url)}">${t(APP_ID, 'Copiar')}</button>
				</li>
			`).join('')}
		</ul>
		<button type="button" class="signdocs-done primary">${t(APP_ID, 'Pronto')}</button>
	`
	result.querySelectorAll('button[data-copy]').forEach((btn) => {
		btn.addEventListener('click', () => {
			navigator.clipboard?.writeText(btn.getAttribute('data-copy'))
			btn.textContent = t(APP_ID, 'Copiado')
		})
	})
	result.querySelector('.signdocs-done').addEventListener('click', () => overlay.remove())

	// Lets the landing page refresh its request list without a page reload.
	// Nothing listens on the Files surface, which is harmless.
	window.dispatchEvent(new CustomEvent('signdocs:session-created'))
}

function openSigningDialog(fileInfo) {
	const overlay = document.createElement('div')
	overlay.className = 'signdocs-overlay'
	overlay.innerHTML = `
		<div class="signdocs-dialog" role="dialog" aria-labelledby="sdb-title">
			<header>
				<h2 id="sdb-title">${t(APP_ID, 'Assinar com SignDocs Brasil')}</h2>
				<button class="signdocs-close" type="button" aria-label="${t(APP_ID, 'Fechar')}">×</button>
			</header>
			<form class="signdocs-form">
				<p class="signdocs-file-name">${escapeHtml(fileInfo.name)}</p>

				<fieldset class="signdocs-signers">
					<legend>${t(APP_ID, 'Signatários')}</legend>
					<div class="signdocs-signer-list"></div>
					<button type="button" class="signdocs-add-signer">+ ${t(APP_ID, 'Adicionar signatário')}</button>
					<p class="signdocs-sequential-hint" hidden>
						${t(APP_ID, 'Os signatários assinarão na ordem listada acima.')}
					</p>
				</fieldset>

				<label>
					${t(APP_ID, 'Modo de assinatura')}
					<!-- For a non-PDF the click/OTP options are removed entirely —
					     see restrictModesForFormat. -->
					<select name="mode">
						<option value="electronic">${t(APP_ID, 'Eletrônica simples (clique)')}</option>
						<option value="click_plus_otp">${t(APP_ID, 'Clique + OTP por email')}</option>
						<option value="digital_certificate">${t(APP_ID, 'Certificado Digital ICP-Brasil (A1/A3)')}</option>
					</select>
					<small class="signdocs-mode-locked-hint" hidden>
						${t(APP_ID, 'Documentos que não são PDF só podem ser assinados com Certificado Digital ICP-Brasil, que gera a assinatura destacada (.p7s). Converta para PDF se quiser usar assinatura eletrônica simples ou OTP.')}
					</small>
				</label>

				<label>
					${t(APP_ID, 'Ordem')}
					<!-- The sequential option is injected only while ICP-Brasil
					     digital-certificate mode is selected — see
					     updateSequentialMode. -->
					<select name="order" disabled>
						<option value="parallel">${t(APP_ID, 'Paralela (qualquer ordem)')}</option>
					</select>
					<small class="signdocs-order-locked-hint">
						${t(APP_ID, 'Assinatura sequencial disponível apenas com Certificado Digital ICP-Brasil.')}
					</small>
				</label>

				<footer>
					<button type="button" class="signdocs-cancel">${t(APP_ID, 'Cancelar')}</button>
					<button type="submit" class="primary">${t(APP_ID, 'Revisar e enviar')}</button>
				</footer>
			</form>
			<div class="signdocs-confirm" hidden></div>
			<div class="signdocs-result" hidden></div>
		</div>
	`

	document.body.appendChild(overlay)

	const signerList = overlay.querySelector('.signdocs-signer-list')
	setupSignerListDragAndDrop(signerList)
	addSignerRow(signerList)

	overlay.querySelector('.signdocs-add-signer').addEventListener('click', () => addSignerRow(signerList))
	overlay.querySelector('.signdocs-close').addEventListener('click', () => overlay.remove())
	overlay.querySelector('.signdocs-cancel').addEventListener('click', () => overlay.remove())

	const modeSelect = overlay.querySelector('select[name=mode]')
	const orderSelect = overlay.querySelector('select[name=order]')
	const sequentialHint = overlay.querySelector('.signdocs-sequential-hint')
	const orderLockedHint = overlay.querySelector('.signdocs-order-locked-hint')
	const modeLockedHint = overlay.querySelector('.signdocs-mode-locked-hint')

	// A non-PDF can only be signed with an ICP-Brasil certificate. The API keeps
	// non-PDF uploads as documentFormat=generic, and the only artifact that path
	// produces is the detached .p7s written by the certificate step — a click or
	// OTP signature on a .docx is recorded and evidenced but yields no signed
	// document to hand back. Rather than let users reach that dead end, drop the
	// options entirely. Lifts once conversion-to-PDF exists (Collabora), which
	// would make click/OTP viable again by signing a PDF rendition instead.
	const restrictModesForFormat = () => {
		if (/\.pdf$/i.test(fileInfo.name)) return
		for (const value of ['electronic', 'click_plus_otp']) {
			modeSelect.querySelector(`option[value="${value}"]`)?.remove()
		}
		modeSelect.value = 'digital_certificate'
		modeLockedHint.hidden = false
	}
	restrictModesForFormat()

	// Sequential ordering belongs to ICP-Brasil digital certificates alone:
	// each signer chains onto the previous signature, so the order is part of
	// how the signature is built rather than a scheduling preference. Every
	// other mode is parallel, so the option is only ever attached to the
	// dropdown while digital-certificate mode is selected — the order is
	// derived from the mode, never chosen on its own, hence the permanently
	// disabled select and the hint that explains what drives it.
	const sequentialOption = document.createElement('option')
	sequentialOption.value = 'sequential'
	sequentialOption.textContent = t(APP_ID, 'Sequencial')

	const updateSequentialMode = () => {
		const isIcp = modeSelect.value === 'digital_certificate'

		if (isIcp) {
			if (!sequentialOption.parentElement) {
				orderSelect.appendChild(sequentialOption)
			}
			orderSelect.value = 'sequential'
		} else {
			sequentialOption.remove()
			orderSelect.value = 'parallel'
		}

		orderLockedHint.textContent = isIcp
			? t(APP_ID, 'Certificado Digital ICP-Brasil exige assinatura sequencial.')
			: t(APP_ID, 'Assinatura sequencial disponível apenas com Certificado Digital ICP-Brasil.')

		// Reordering controls (numbers, arrows, drag handles) only make sense
		// when the order is actually meaningful.
		sequentialHint.hidden = !isIcp
		signerList.classList.toggle('signdocs-sequential', isIcp)
	}
	modeSelect.addEventListener('change', updateSequentialMode)
	updateSequentialMode()

	// Reads and validates the signer rows. Returns null (after alerting and
	// focusing the offending field) when something is off, so the caller can
	// keep the user on the form.
	const collectSigners = () => {
		const signers = []
		const rowsArr = Array.from(signerList.querySelectorAll('.signdocs-signer-row'))
		for (let idx = 0; idx < rowsArr.length; idx++) {
			const row = rowsArr[idx]
			const name = row.querySelector('input[name=name]').value.trim()
			const email = row.querySelector('input[name=email]').value.trim()
			const docInput = row.querySelector('input[name=cpf_cnpj]')
			const docDigits = docInput.value.replace(/\D+/g, '')
			if (!name || !email) continue

			const who = name || email
			if (docDigits.length !== 11 && docDigits.length !== 14) {
				docInput.focus()
				alert(t(APP_ID, 'CPF ou CNPJ inválido para %s. Digite 11 dígitos para CPF ou 14 para CNPJ.').replace('%s', who))
				return null
			}
			const signer = { name, email }
			if (docDigits.length === 11) {
				if (!isValidCpf(docDigits)) {
					docInput.focus()
					alert(t(APP_ID, 'CPF inválido para %s. Verifique os dígitos verificadores.').replace('%s', who))
					return null
				}
				signer.cpf = docDigits
			} else {
				if (!isValidCnpj(docDigits)) {
					docInput.focus()
					alert(t(APP_ID, 'CNPJ inválido para %s. Verifique os dígitos verificadores.').replace('%s', who))
					return null
				}
				signer.cnpj = docDigits
			}
			signers.push(signer)
		}

		if (signers.length === 0) {
			alert(t(APP_ID, 'Adicione pelo menos um signatário com nome e email.'))
			return null
		}
		return signers
	}

	const sendForSignature = async (signers, options) => {
		const response = await fetch(generateUrl('/apps/' + APP_ID + '/api/v1/sessions'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': window.OC?.requestToken || '',
			},
			body: JSON.stringify({
				fileId: Number(fileInfo.id),
				signers,
				options,
			}),
		})
		const data = await response.json()
		if (!response.ok) {
			throw new Error(data?.message || 'Falha ao criar sessão de assinatura')
		}
		renderResult(overlay, data)
	}

	// Submitting the form no longer sends anything — it only advances to the
	// review step, which is where the actual POST is confirmed.
	overlay.querySelector('form').addEventListener('submit', (ev) => {
		ev.preventDefault()
		const signers = collectSigners()
		if (signers === null) return

		// Read the selects through the refs already in scope rather than the
		// form's named properties: the order select is disabled now that it's
		// derived from the mode, which excludes it from the form's own data.
		const options = { mode: modeSelect.value, order: orderSelect.value }

		renderConfirmStep(
			overlay,
			{ fileName: fileInfo.name, signers, mode: options.mode, order: options.order },
			() => sendForSignature(signers, options),
		)
	})
}

const action = new FileAction({
	id: 'signdocs-sign',
	displayName: () => t(APP_ID, 'Assinar com SignDocs Brasil'),
	iconSvgInline: () => SIGN_ICON,
	enabled: (nodes) => {
		if (nodes.length !== 1) return false
		const mime = nodes[0].mime
		if (!mime || !supportedMimeTypes.has(mime)) return false
		// Need at least read permission on the file.
		return (nodes[0].permissions & Permission.READ) !== 0
	},
	async exec(node) {
		openSigningDialog({ id: node.fileid, name: node.basename, mime: node.mime })
		// Returning null tells the Files app the action handled itself
		// (no navigation, no further side effects).
		return null
	},
	order: 50,
})

registerFileAction(action)

/**
 * Per-document status panel: what has been sent for this file, and a way to
 * stop anything still in flight. Opened from the Files context menu, which is
 * where users are when they wonder about a document — the landing page list
 * covers every request, this one covers the one in front of them.
 */
function openStatusDialog(fileInfo) {
	const overlay = document.createElement('div')
	overlay.className = 'signdocs-overlay'
	overlay.innerHTML = `
		<div class="signdocs-dialog" role="dialog" aria-labelledby="sdb-status-title">
			<header>
				<h2 id="sdb-status-title">${t(APP_ID, 'Status da assinatura')}</h2>
				<button class="signdocs-close" type="button" aria-label="${t(APP_ID, 'Fechar')}">×</button>
			</header>
			<div class="signdocs-status-body">
				<p class="signdocs-file-name">${escapeHtml(fileInfo.name)}</p>
				<div class="signdocs-status-message"></div>
				<ul class="signdocs-requests-list"></ul>
			</div>
		</div>
	`
	document.body.appendChild(overlay)
	overlay.querySelector('.signdocs-close').addEventListener('click', () => overlay.remove())

	const list = overlay.querySelector('.signdocs-requests-list')
	const message = overlay.querySelector('.signdocs-status-message')
	const setMessage = (text, kind = 'info') => {
		message.textContent = text ?? ''
		message.dataset.kind = kind
		message.hidden = !text
	}

	const reload = async () => {
		let rows
		try {
			rows = await fetchRequestsForFile(fileInfo.id)
		} catch (err) {
			setMessage(t(APP_ID, 'Não foi possível carregar suas solicitações.'), 'error')
			return
		}
		if (!Array.isArray(rows) || rows.length === 0) {
			list.innerHTML = ''
			setMessage(t(APP_ID, 'Nenhuma solicitação de assinatura para este documento.'))
			return
		}
		setMessage('')
		// The dialog header already names the file, so don't repeat it per row.
		renderRequests(list, rows, { onReload: reload, onMessage: setMessage, showName: false })
	}

	reload()
}

const statusAction = new FileAction({
	id: 'signdocs-status',
	displayName: () => t(APP_ID, 'Status da assinatura'),
	iconSvgInline: () => STATUS_ICON,
	enabled: (nodes) => {
		if (nodes.length !== 1) return false
		const mime = nodes[0].mime
		if (!mime || !supportedMimeTypes.has(mime)) return false
		return (nodes[0].permissions & Permission.READ) !== 0
	},
	async exec(node) {
		openStatusDialog({ id: node.fileid, name: node.basename })
		return null
	},
	order: 51,
})

registerFileAction(statusAction)

// Allow the top-nav landing page (and any future entry surface) to drive
// the same dialog without going through the Files app's right-click menu.
// Dispatching a `signdocs:open-dialog` CustomEvent with `{detail: {id, name,
// mime}}` opens the modal as if the user had right-clicked the file.
window.addEventListener('signdocs:open-dialog', (event) => {
	const { id, name, mime } = event.detail || {}
	if (!id || !name) return
	openSigningDialog({ id, name, mime })
})

// Consumed only by the jsdom suite in tests/js, which bundles this file to
// CommonJS. esbuild drops exports from the IIFE production build, so
// js/signdocs-files-action.js is byte-for-byte unaffected by this line.
export { openSigningDialog, openStatusDialog, formatCpfCnpj, modeLabel, orderLabel }
