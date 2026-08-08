/**
 * Signature-request list and cancellation, shared by both surfaces.
 *
 * The landing page renders every request the user has; the Files action renders
 * just the ones for one document. Same markup, same cancel flow, so the two
 * cannot drift — which matters most for the confirmation wording, since that is
 * what tells the user what a cancel actually destroys.
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'signdocs_brasil'

export function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]))
}

export function statusLabel(status) {
	if (status === 'completed') return t(APP_ID, 'Assinado')
	if (status === 'cancelled') return t(APP_ID, 'Cancelado')
	if (status === 'expired') return t(APP_ID, 'Expirado')
	if (status === 'failed') return t(APP_ID, 'Falhou')
	return t(APP_ID, 'Pendente')
}

export function formatDate(seconds) {
	if (!seconds) return ''
	return new Date(Number(seconds) * 1000).toLocaleString()
}

function requesttoken() {
	return window.OC?.requestToken || ''
}

/** Every request for the current user. */
export async function fetchRequests() {
	const response = await fetch(generateUrl('/apps/' + APP_ID + '/api/v1/sessions'), {
		headers: { 'requesttoken': requesttoken() },
	})
	if (!response.ok) throw new Error(String(response.status))
	return response.json()
}

/** Requests for one document. */
export async function fetchRequestsForFile(fileId) {
	const response = await fetch(
		generateUrl('/apps/' + APP_ID + '/api/v1/files/' + encodeURIComponent(fileId) + '/sessions'),
		{ headers: { 'requesttoken': requesttoken() } },
	)
	if (!response.ok) throw new Error(String(response.status))
	return response.json()
}

/**
 * Render rows into `list`.
 *
 * @param {HTMLElement} list        the <ul> to fill
 * @param {Array} rows              rows as returned by the API
 * @param {object} handlers
 * @param {Function} handlers.onReload   re-fetch and re-render
 * @param {Function} handlers.onMessage  surface a message to the user
 * @param {boolean} [handlers.showName]  include the document name (false when
 *                                       the surface already names the file)
 */
export function renderRequests(list, rows, { onReload, onMessage, showName = true }) {
	list.innerHTML = rows.map((row) => {
		const name = row.fileName || t(APP_ID, 'documento removido')
		const signers = row.signerCount > 1
			? t(APP_ID, '%n signatários').replace('%n', String(row.signerCount))
			: t(APP_ID, '1 signatário')
		const meta = [showName ? '' : signers, formatDate(row.createdAt)].filter(Boolean).join(' · ')
		return `
			<li class="signdocs-request" data-session-id="${escapeHtml(row.sessionId)}">
				<div class="signdocs-request-main">
					${showName ? `<span class="signdocs-request-name">${escapeHtml(name)}</span>` : ''}
					<span class="signdocs-request-meta">
						${escapeHtml(showName ? `${signers} · ${formatDate(row.createdAt)}` : meta)}
					</span>
				</div>
				<span class="signdocs-request-status" data-status="${escapeHtml(row.status)}">
					${escapeHtml(statusLabel(row.status))}
				</span>
				<div class="signdocs-request-actions">
					${row.selfSigner
						? `<button type="button" class="signdocs-request-sign primary">${t(APP_ID, 'Assinar')}</button>`
						: ''}
					${row.cancellable
						? `<button type="button" class="signdocs-request-cancel">${t(APP_ID, 'Cancelar')}</button>`
						: ''}
				</div>
			</li>
		`
	}).join('')

	list.querySelectorAll('.signdocs-request-cancel').forEach((btn) => {
		btn.addEventListener('click', () => {
			confirmCancel(btn.closest('.signdocs-request'), { onReload, onMessage })
		})
	})

	list.querySelectorAll('.signdocs-request-sign').forEach((btn) => {
		btn.addEventListener('click', () => {
			openOwnSigningLink(btn.closest('.signdocs-request'), btn, { onMessage })
		})
	})
}

/**
 * Open the caller's own signing page for this request.
 *
 * Nobody is emailed when you are a signer on your own send — the addresses
 * match — so the link only ever existed in the dialog that has since closed.
 * This mints a fresh one and goes straight there: the URL is a credential, so
 * it is never rendered, never copied, and never held longer than the navigation.
 *
 * The tab is opened *before* the await and navigated afterwards. Browsers only
 * treat window.open as user-initiated inside the click handler's own turn, so
 * opening after the fetch resolves is what gets caught by the popup blocker.
 */
export async function openOwnSigningLink(row, button, { onMessage }) {
	const sessionId = row.getAttribute('data-session-id')
	const tab = window.open('', '_blank', 'noopener')
	button.disabled = true
	button.textContent = t(APP_ID, 'Abrindo…')

	try {
		const response = await fetch(
			generateUrl('/apps/' + APP_ID + '/api/v1/sessions/' + encodeURIComponent(sessionId) + '/own-link'),
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'requesttoken': requesttoken() },
			},
		)
		const data = await response.json()
		if (!response.ok) {
			throw new Error(data?.message || data?.error || String(response.status))
		}
		if (tab) {
			tab.location = data.url
		} else {
			// Popup blocked. Navigating the current tab still beats showing the
			// user a URL we have just gone to some trouble not to display.
			window.location = data.url
		}
	} catch (err) {
		tab?.close()
		button.disabled = false
		button.textContent = t(APP_ID, 'Assinar')
		onMessage(t(APP_ID, 'Não foi possível abrir seu link de assinatura: ') + err.message, 'error')
	}
}

/**
 * Cancelling kills every pending signing link, so ask first — inline in the
 * row rather than a browser confirm(), which cannot explain what survives.
 */
export function confirmCancel(row, { onReload, onMessage }) {
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
		.addEventListener('click', () => onReload())
	actions.querySelector('.signdocs-request-confirm-yes')
		.addEventListener('click', (ev) => cancelRequest(row, ev.target, { onReload, onMessage }))
}

export async function cancelRequest(row, button, { onReload, onMessage }) {
	const sessionId = row.getAttribute('data-session-id')
	button.disabled = true
	button.textContent = t(APP_ID, 'Cancelando…')

	try {
		const response = await fetch(
			generateUrl('/apps/' + APP_ID + '/api/v1/sessions/' + encodeURIComponent(sessionId) + '/cancel'),
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'requesttoken': requesttoken() },
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
		onMessage(message, 'info')
		await onReload()
	} catch (err) {
		// Report failure *in the row*. A page-level message is easy to miss —
		// it can be scrolled far from the button that was just pressed — and the
		// row silently staying "Pendente" reads as the click having done nothing.
		// The upstream call can fail transiently (an API token timeout does it),
		// so leave a retry rather than forcing a full reload.
		showRowError(row, err.message, { onReload, onMessage })
		onMessage(t(APP_ID, 'Não foi possível cancelar: ') + err.message, 'error')
	}
}

/** Inline failure state with a retry, replacing the row's action area. */
function showRowError(row, detail, handlers) {
	const actions = row.querySelector('.signdocs-request-actions')
	actions.innerHTML = `
		<span class="signdocs-request-error">
			<span class="signdocs-request-error-text">
				${escapeHtml(t(APP_ID, 'Não foi possível cancelar: ') + detail)}
			</span>
			<button type="button" class="signdocs-request-retry">${t(APP_ID, 'Tentar novamente')}</button>
		</span>
	`
	actions.querySelector('.signdocs-request-retry').addEventListener('click', (ev) => {
		cancelRequest(row, ev.target, handlers)
	})
}
