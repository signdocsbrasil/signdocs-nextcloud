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

const SIGN_ICON = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path fill="currentColor" d="M3 17v3h3l11-11-3-3L3 17zm17.7-11.3a1 1 0 0 0 0-1.4l-2-2a1 1 0 0 0-1.4 0l-2 2 3.4 3.4 2-2z"/>
</svg>
`.trim()

function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]))
}

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

function renderResult(overlay, data) {
	const form = overlay.querySelector('form')
	const result = overlay.querySelector('.signdocs-result')
	form.hidden = true
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
					<select name="mode">
						<option value="electronic">${t(APP_ID, 'Eletrônica simples (clique)')}</option>
						<option value="click_plus_otp">${t(APP_ID, 'Clique + OTP por email')}</option>
						<option value="digital_certificate">${t(APP_ID, 'Certificado Digital ICP-Brasil (A1/A3)')}</option>
					</select>
				</label>

				<label>
					${t(APP_ID, 'Ordem')}
					<select name="order">
						<option value="parallel">${t(APP_ID, 'Paralela (qualquer ordem)')}</option>
						<option value="sequential">${t(APP_ID, 'Sequencial')}</option>
					</select>
					<small class="signdocs-order-locked-hint" hidden>
						${t(APP_ID, 'Certificado Digital ICP-Brasil exige assinatura sequencial.')}
					</small>
				</label>

				<footer>
					<button type="button" class="signdocs-cancel">${t(APP_ID, 'Cancelar')}</button>
					<button type="submit" class="primary">${t(APP_ID, 'Enviar para assinatura')}</button>
				</footer>
			</form>
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

	// Remembers the user's chosen order BEFORE we force-flipped it to
	// sequential on entering ICP mode. When they switch the mode dropdown
	// back to a non-ICP option, we restore this value so they don't get
	// stranded in sequential UI (with drag handles, numbers, arrows still
	// active) just because ICP overrode their pick.
	let savedOrderBeforeIcp = null

	const updateSequentialMode = () => {
		// Digital-certificate signing requires sequential ordering — the
		// signer must consume the previous signer's chained signature, so
		// parallel mode is not a valid choice here. Force it and lock the
		// dropdown so the user can't accidentally violate the constraint.
		const requiresSequential = modeSelect.value === 'digital_certificate'
		const wasIcpLocked = orderSelect.disabled

		if (requiresSequential && !wasIcpLocked) {
			savedOrderBeforeIcp = orderSelect.value
			orderSelect.value = 'sequential'
			orderSelect.disabled = true
		} else if (!requiresSequential && wasIcpLocked) {
			orderSelect.value = savedOrderBeforeIcp ?? 'parallel'
			orderSelect.disabled = false
			savedOrderBeforeIcp = null
		}

		const isSequential = orderSelect.value === 'sequential'
		sequentialHint.hidden = !isSequential
		orderLockedHint.hidden = !requiresSequential
		signerList.classList.toggle('signdocs-sequential', isSequential)
	}
	modeSelect.addEventListener('change', updateSequentialMode)
	orderSelect.addEventListener('change', updateSequentialMode)
	updateSequentialMode()

	overlay.querySelector('form').addEventListener('submit', async (ev) => {
		ev.preventDefault()
		const submitBtn = ev.target.querySelector('button[type=submit]')
		submitBtn.disabled = true
		submitBtn.textContent = t(APP_ID, 'Enviando…')

		const restoreSubmit = () => {
			submitBtn.disabled = false
			submitBtn.textContent = t(APP_ID, 'Enviar para assinatura')
		}

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
				restoreSubmit()
				docInput.focus()
				alert(t(APP_ID, 'CPF ou CNPJ inválido para %s. Digite 11 dígitos para CPF ou 14 para CNPJ.').replace('%s', who))
				return
			}
			const signer = { name, email }
			if (docDigits.length === 11) {
				if (!isValidCpf(docDigits)) {
					restoreSubmit()
					docInput.focus()
					alert(t(APP_ID, 'CPF inválido para %s. Verifique os dígitos verificadores.').replace('%s', who))
					return
				}
				signer.cpf = docDigits
			} else {
				if (!isValidCnpj(docDigits)) {
					restoreSubmit()
					docInput.focus()
					alert(t(APP_ID, 'CNPJ inválido para %s. Verifique os dígitos verificadores.').replace('%s', who))
					return
				}
				signer.cnpj = docDigits
			}
			signers.push(signer)
		}

		if (signers.length === 0) {
			submitBtn.disabled = false
			submitBtn.textContent = t(APP_ID, 'Enviar para assinatura')
			alert(t(APP_ID, 'Adicione pelo menos um signatário com nome e email.'))
			return
		}

		const form = ev.target
		const payload = {
			fileId: Number(fileInfo.id),
			signers,
			options: {
				mode: form.mode.value,
				order: form.order.value,
			},
		}

		try {
			const response = await fetch(generateUrl('/apps/' + APP_ID + '/api/v1/sessions'), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': window.OC?.requestToken || '',
				},
				body: JSON.stringify(payload),
			})
			const data = await response.json()
			if (!response.ok) {
				throw new Error(data?.message || 'Falha ao criar sessão de assinatura')
			}
			renderResult(overlay, data)
		} catch (err) {
			submitBtn.disabled = false
			submitBtn.textContent = t(APP_ID, 'Enviar para assinatura')
			alert(t(APP_ID, 'Erro: ') + err.message)
		}
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
