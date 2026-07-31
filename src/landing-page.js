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
import { fetchRequests, renderRequests } from './signing-requests'

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
// Rendering and cancellation live in signing-requests.js so this page and the
// Files action share one implementation.

async function loadRequests() {
	const section = document.querySelector('.signdocs-requests')
	const list = document.querySelector('.signdocs-requests-list')
	if (!section || !list) return

	let rows
	try {
		rows = await fetchRequests()
	} catch (err) {
		// A failed refresh must not blank a list that is already showing.
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
	renderRequests(list, rows, {
		onReload: loadRequests,
		onMessage: (message, kind) => setStatus(message, kind),
	})
}

document.querySelector('.signdocs-requests-refresh')?.addEventListener('click', () => loadRequests())

// Refresh after the signing dialog reports a successful send, so a new request
// shows up without a page reload.
window.addEventListener('signdocs:session-created', () => loadRequests())

loadRequests()
