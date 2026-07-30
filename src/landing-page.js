/**
 * Landing page wired to the three intake paths:
 *   - "pick-from-files"  → opens NC file picker, returns a path → resolves
 *                          to a fileId and dispatches the signing event.
 *   - "upload-local"     → triggers the hidden <input type=file>, multipart-
 *                          POSTs to /api/v1/intake/upload, gets a fileId.
 *   - "upload-from-url"  → prompts for URL, POSTs to /api/v1/intake/url
 *                          (server-side fetcher with SSRF guards), gets a fileId.
 *
 * After producing a fileId, dispatches a `signdocs:open-dialog` CustomEvent
 * the existing files-action bundle listens for. That bundle is also loaded
 * on this page so the dialog and post-create result UX stays the canonical
 * one — the landing page is just a fileId factory.
 */
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

const APP_ID = 'signdocs_brasil'

const state = loadState(APP_ID, 'signdocs_landing', {
	apiBase: '/apps/' + APP_ID + '/api/v1',
	supportedMimeTypes: [
		'application/pdf',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
	],
	maxUploadSize: 50 * 1024 * 1024,
})

function setStatus(text, kind = 'info') {
	const el = document.querySelector('.signdocs-landing-status')
	if (!el) return
	if (text === null || text === '') {
		el.hidden = true
		el.textContent = ''
		return
	}
	el.hidden = false
	el.dataset.kind = kind
	el.textContent = text
}

function dispatchOpenDialog(fileInfo) {
	// The files-action bundle (loaded on this page too) listens for this
	// event and opens the same modal users see from the right-click flow.
	window.dispatchEvent(new CustomEvent('signdocs:open-dialog', {
		detail: { id: fileInfo.fileId, name: fileInfo.name, mime: fileInfo.mimeType ?? null },
	}))
	setStatus('')
}

function pickFromFiles() {
	// NC's legacy global filepicker is still available on every authenticated
	// page in NC 27-30. Avoids pulling @nextcloud/dialogs into the landing
	// bundle (which would balloon it to ~1.3MB by hauling in Vue + NcModal).
	if (!window.OC?.dialogs?.filepicker) {
		setStatus(t(APP_ID, 'Seletor de arquivos indisponível nesta versão do Nextcloud.'), 'error')
		return
	}
	window.OC.dialogs.filepicker(
		t(APP_ID, 'Escolher um documento para assinar'),
		async (path) => {
			if (!path) return
			setStatus(t(APP_ID, 'Carregando arquivo selecionado...'))
			try {
				const fileId = await resolvePathToFileId(path)
				const name = path.split('/').filter(Boolean).pop() || 'documento'
				dispatchOpenDialog({ fileId, name })
			} catch (err) {
				setStatus(t(APP_ID, 'Erro: ') + err.message, 'error')
			}
		},
		false, // multiselect
		state.supportedMimeTypes,
		true, // modal
	)
}

async function resolvePathToFileId(path) {
	// PROPFIND on webdav is the universally-supported way to map a NC path
	// to its fileId. Works without depending on the Files app's internal
	// REST endpoints which have moved across NC versions.
	const userId = window.OC?.getCurrentUser?.()?.uid
	if (!userId) throw new Error(t(APP_ID, 'Usuário não autenticado'))
	const davUrl = generateUrl('/remote.php/dav/files/' + encodeURIComponent(userId) + path)

	const body = `<?xml version="1.0" encoding="UTF-8"?>
		<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
			<d:prop>
				<oc:fileid />
			</d:prop>
		</d:propfind>`

	const response = await fetch(davUrl, {
		method: 'PROPFIND',
		headers: {
			'Content-Type': 'application/xml',
			'Depth': '0',
			'requesttoken': window.OC?.requestToken || '',
		},
		body,
	})
	if (!response.ok) {
		throw new Error(t(APP_ID, 'Não foi possível resolver o arquivo: ') + response.status)
	}
	const xml = await response.text()
	const match = xml.match(/<oc:fileid>(\d+)<\/oc:fileid>/)
	if (!match) throw new Error(t(APP_ID, 'Não foi possível obter o ID do arquivo.'))
	return Number(match[1])
}

async function uploadLocal() {
	const input = document.getElementById('signdocs-landing-file-input')
	if (!input) return

	const onChange = async () => {
		input.removeEventListener('change', onChange)
		const file = input.files?.[0]
		input.value = '' // allow re-uploading the same name
		if (!file) return

		if (file.size > state.maxUploadSize) {
			setStatus(t(APP_ID, 'Arquivo excede o limite de %s MB.').replace('%s', String(Math.round(state.maxUploadSize / (1024 * 1024)))), 'error')
			return
		}
		if (!state.supportedMimeTypes.includes(file.type)) {
			setStatus(t(APP_ID, 'Tipo de arquivo não suportado: ') + (file.type || t(APP_ID, 'desconhecido')), 'error')
			return
		}

		setStatus(t(APP_ID, 'Enviando %s...').replace('%s', file.name))
		const form = new FormData()
		form.append('file', file)

		try {
			const response = await fetch(generateUrl(state.apiBase + '/intake/upload'), {
				method: 'POST',
				headers: { 'requesttoken': window.OC?.requestToken || '' },
				body: form,
			})
			const data = await response.json()
			if (!response.ok) throw new Error(data.message || t(APP_ID, 'Falha no upload'))
			dispatchOpenDialog({ fileId: data.fileId, name: data.name, mimeType: file.type })
		} catch (err) {
			setStatus(t(APP_ID, 'Erro: ') + err.message, 'error')
		}
	}

	input.addEventListener('change', onChange)
	input.click()
}

async function uploadFromUrl() {
	const url = window.prompt(
		t(APP_ID, 'Cole a URL pública do documento (HTTPS, PDF/DOCX/ODT, máx %s MB):')
			.replace('%s', String(Math.round(state.maxUploadSize / (1024 * 1024)))),
		'https://',
	)
	if (!url || url.trim() === '' || url.trim() === 'https://') return
	const trimmed = url.trim()

	setStatus(t(APP_ID, 'Buscando %s...').replace('%s', trimmed))
	try {
		const response = await fetch(generateUrl(state.apiBase + '/intake/url'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': window.OC?.requestToken || '',
			},
			body: JSON.stringify({ url: trimmed }),
		})
		const data = await response.json()
		if (!response.ok) throw new Error(data.message || t(APP_ID, 'Falha ao buscar URL'))
		dispatchOpenDialog({ fileId: data.fileId, name: data.name, mimeType: data.mimeType })
	} catch (err) {
		setStatus(t(APP_ID, 'Erro: ') + err.message, 'error')
	}
}

document.querySelectorAll('.signdocs-landing-button').forEach((btn) => {
	btn.addEventListener('click', () => {
		const action = btn.getAttribute('data-action')
		if (action === 'pick-from-files') pickFromFiles()
		else if (action === 'upload-local') uploadLocal()
		else if (action === 'upload-from-url') uploadFromUrl()
	})
})

// ─── Signature requests: list + cancel ──────────────────────────────────
//
// The only place in the UI where an in-flight request can be seen and stopped.
// Everything else creates requests; without this, a send is irreversible from
// the GUI.

function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]))
}

function statusLabel(status) {
	if (status === 'completed') return t(APP_ID, 'Assinado')
	if (status === 'cancelled') return t(APP_ID, 'Cancelado')
	if (status === 'expired') return t(APP_ID, 'Expirado')
	if (status === 'failed') return t(APP_ID, 'Falhou')
	return t(APP_ID, 'Pendente')
}

function formatDate(seconds) {
	if (!seconds) return ''
	return new Date(Number(seconds) * 1000).toLocaleString()
}

async function loadRequests() {
	const section = document.querySelector('.signdocs-requests')
	const list = document.querySelector('.signdocs-requests-list')
	if (!section || !list) return

	let rows
	try {
		const response = await fetch(generateUrl(state.apiBase + '/sessions'), {
			headers: { 'requesttoken': window.OC?.requestToken || '' },
		})
		if (!response.ok) throw new Error(String(response.status))
		rows = await response.json()
	} catch (err) {
		// A failed list must not blank the section if it's already showing.
		if (section.hidden) return
		setStatus(t(APP_ID, 'Não foi possível carregar suas solicitações.'), 'error')
		return
	}

	if (!Array.isArray(rows) || rows.length === 0) {
		section.hidden = true
		list.innerHTML = ''
		return
	}

	section.hidden = false
	list.innerHTML = rows.map((row) => {
		const name = row.fileName || t(APP_ID, 'documento removido')
		const signers = row.signerCount > 1
			? t(APP_ID, '%n signatários').replace('%n', String(row.signerCount))
			: t(APP_ID, '1 signatário')
		return `
			<li class="signdocs-request" data-session-id="${escapeHtml(row.sessionId)}">
				<div class="signdocs-request-main">
					<span class="signdocs-request-name">${escapeHtml(name)}</span>
					<span class="signdocs-request-meta">
						${escapeHtml(signers)} · ${escapeHtml(formatDate(row.createdAt))}
					</span>
				</div>
				<span class="signdocs-request-status" data-status="${escapeHtml(row.status)}">
					${escapeHtml(statusLabel(row.status))}
				</span>
				<div class="signdocs-request-actions">
					${row.cancellable
						? `<button type="button" class="signdocs-request-cancel">${t(APP_ID, 'Cancelar')}</button>`
						: ''}
				</div>
			</li>
		`
	}).join('')

	list.querySelectorAll('.signdocs-request-cancel').forEach((btn) => {
		btn.addEventListener('click', () => confirmCancel(btn.closest('.signdocs-request')))
	})
}

/**
 * Cancelling kills every pending signing link, so ask first — inline, in the
 * row, rather than a browser confirm() that says nothing about what survives.
 */
function confirmCancel(row) {
	if (!row || row.querySelector('.signdocs-request-confirm')) return
	const actions = row.querySelector('.signdocs-request-actions')
	actions.innerHTML = `
		<span class="signdocs-request-confirm">
			<span class="signdocs-request-confirm-text">
				${t(APP_ID, 'Cancelar? Os signatários pendentes não poderão mais assinar. Assinaturas já coletadas são preservadas.')}
			</span>
			<button type="button" class="signdocs-request-confirm-yes">${t(APP_ID, 'Confirmar')}</button>
			<button type="button" class="signdocs-request-confirm-no">${t(APP_ID, 'Voltar')}</button>
		</span>
	`
	actions.querySelector('.signdocs-request-confirm-no')
		.addEventListener('click', () => loadRequests())
	actions.querySelector('.signdocs-request-confirm-yes')
		.addEventListener('click', (ev) => cancelRequest(row, ev.target))
}

async function cancelRequest(row, button) {
	const sessionId = row.getAttribute('data-session-id')
	button.disabled = true
	button.textContent = t(APP_ID, 'Cancelando…')

	try {
		const response = await fetch(
			generateUrl(state.apiBase + '/sessions/' + encodeURIComponent(sessionId) + '/cancel'),
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': window.OC?.requestToken || '',
				},
			},
		)
		const data = await response.json()
		if (!response.ok) {
			throw new Error(data?.message || data?.error || String(response.status))
		}

		// Report what actually happened: cancelling stops pending signers but
		// never invalidates signatures already collected.
		let message = t(APP_ID, 'Solicitação cancelada.')
		if (data.preservedSignedCount > 0) {
			message += ' ' + t(APP_ID, '%n assinatura(s) já coletada(s) foram preservadas.')
				.replace('%n', String(data.preservedSignedCount))
		}
		setStatus(message, 'info')
	} catch (err) {
		setStatus(t(APP_ID, 'Não foi possível cancelar: ') + err.message, 'error')
	}

	await loadRequests()
}

document.querySelector('.signdocs-requests-refresh')?.addEventListener('click', () => loadRequests())

// Refresh after the signing dialog reports a successful send, so a new request
// shows up without a page reload.
window.addEventListener('signdocs:session-created', () => loadRequests())

loadRequests()
