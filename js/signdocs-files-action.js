/**
 * Registers a Files app action: right-click any supported document → "Assinar com SignDocs".
 *
 * Front-end has no direct knowledge of SignDocs API — it talks only to this app's
 * backend, which holds credentials and proxies to the SignDocs API via the PHP SDK.
 */
(function () {
	'use strict'

	const APP_ID = 'signdocs_brasil'

	const initialState = OCP?.InitialState?.loadState
		? OCP.InitialState.loadState(APP_ID, 'signdocs')
		: { apiBase: '/apps/' + APP_ID + '/api/v1', supportedMimeTypes: [] }

	const supportedMimeTypes = new Set(initialState.supportedMimeTypes || [])

	function t(key, fallback, params) {
		return OC?.L10N?.translate
			? OC.L10N.translate(APP_ID, fallback, params)
			: fallback
	}

	function openSigningDialog(fileInfo) {
		const overlay = document.createElement('div')
		overlay.className = 'signdocs-overlay'
		overlay.innerHTML = `
			<div class="signdocs-dialog" role="dialog" aria-labelledby="sdb-title">
				<header>
					<h2 id="sdb-title">${t('title', 'Assinar com SignDocs')}</h2>
					<button class="signdocs-close" type="button" aria-label="${t('close', 'Fechar')}">×</button>
				</header>
				<form class="signdocs-form">
					<p class="signdocs-file-name">${escapeHtml(fileInfo.name)}</p>

					<fieldset class="signdocs-signers">
						<legend>${t('signers', 'Signatários')}</legend>
						<div class="signdocs-signer-list"></div>
						<button type="button" class="signdocs-add-signer">+ ${t('add_signer', 'Adicionar signatário')}</button>
					</fieldset>

					<label>
						${t('mode', 'Modo de assinatura')}
						<select name="mode">
							<option value="electronic">${t('mode_electronic', 'Eletrônica simples')}</option>
							<option value="icp_a1">${t('mode_a1', 'ICP-Brasil A1')}</option>
							<option value="icp_a3">${t('mode_a3', 'ICP-Brasil A3')}</option>
						</select>
					</label>

					<label>
						${t('order', 'Ordem')}
						<select name="order">
							<option value="parallel">${t('order_parallel', 'Paralela (qualquer ordem)')}</option>
							<option value="sequential">${t('order_sequential', 'Sequencial')}</option>
						</select>
					</label>

					<label>
						${t('validity', 'Validade (dias)')}
						<input type="number" name="validityDays" min="1" max="365" value="30" />
					</label>

					<label>
						${t('notify', 'Canal de notificação')}
						<select name="notify">
							<option value="email">Email</option>
							<option value="whatsapp">WhatsApp</option>
							<option value="telegram">Telegram</option>
						</select>
					</label>

					<footer>
						<button type="button" class="signdocs-cancel">${t('cancel', 'Cancelar')}</button>
						<button type="submit" class="primary">${t('send', 'Enviar para assinatura')}</button>
					</footer>
				</form>
				<div class="signdocs-result" hidden></div>
			</div>
		`

		document.body.appendChild(overlay)

		const signerList = overlay.querySelector('.signdocs-signer-list')
		addSignerRow(signerList)

		overlay.querySelector('.signdocs-add-signer').addEventListener('click', () => addSignerRow(signerList))
		overlay.querySelector('.signdocs-close').addEventListener('click', () => overlay.remove())
		overlay.querySelector('.signdocs-cancel').addEventListener('click', () => overlay.remove())

		overlay.querySelector('form').addEventListener('submit', async (ev) => {
			ev.preventDefault()
			const submitBtn = ev.target.querySelector('button[type=submit]')
			submitBtn.disabled = true
			submitBtn.textContent = t('sending', 'Enviando…')

			const signers = Array.from(signerList.querySelectorAll('.signdocs-signer-row'))
				.map((row) => ({
					name: row.querySelector('input[name=name]').value.trim(),
					email: row.querySelector('input[name=email]').value.trim(),
					cpf: row.querySelector('input[name=cpf]').value.trim() || undefined,
				}))
				.filter((s) => s.name && s.email)

			if (signers.length === 0) {
				submitBtn.disabled = false
				submitBtn.textContent = t('send', 'Enviar para assinatura')
				alert(t('signers_required', 'Adicione pelo menos um signatário com nome e email.'))
				return
			}

			const form = ev.target
			const payload = {
				fileId: Number(fileInfo.id),
				signers,
				options: {
					mode: form.mode.value,
					order: form.order.value,
					validityDays: Number(form.validityDays.value),
					notify: form.notify.value,
				},
			}

			try {
				const response = await fetch(initialState.apiBase + '/sessions', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'requesttoken': OC?.requestToken || '',
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
				submitBtn.textContent = t('send', 'Enviar para assinatura')
				alert(t('error_create', 'Erro: ') + err.message)
			}
		})
	}

	function addSignerRow(container) {
		const row = document.createElement('div')
		row.className = 'signdocs-signer-row'
		row.innerHTML = `
			<input type="text" name="name" placeholder="${t('signer_name', 'Nome completo')}" required />
			<input type="email" name="email" placeholder="${t('signer_email', 'Email')}" required />
			<input type="text" name="cpf" placeholder="${t('signer_cpf', 'CPF (opcional)')}" />
			<button type="button" class="signdocs-remove-signer" aria-label="${t('remove', 'Remover')}">×</button>
		`
		row.querySelector('.signdocs-remove-signer').addEventListener('click', () => row.remove())
		container.appendChild(row)
	}

	function renderResult(overlay, data) {
		const form = overlay.querySelector('form')
		const result = overlay.querySelector('.signdocs-result')
		form.hidden = true
		result.hidden = false
		const links = data?.metadata?.shareLinks || []
		result.innerHTML = `
			<h3>${t('sent', 'Enviado!')}</h3>
			<p>${t('sent_message', 'Cada signatário receberá um link para assinar.')}</p>
			<ul class="signdocs-share-links">
				${links
					.map(
						(l) =>
							`<li><strong>${escapeHtml(l.signerEmail || '')}</strong>: <a href="${escapeHtml(l.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(l.url)}</a> <button type="button" data-copy="${escapeHtml(l.url)}">${t('copy', 'Copiar')}</button></li>`
					)
					.join('')}
			</ul>
			<button type="button" class="signdocs-done primary">${t('done', 'Pronto')}</button>
		`
		result.querySelectorAll('button[data-copy]').forEach((btn) => {
			btn.addEventListener('click', () => {
				navigator.clipboard?.writeText(btn.getAttribute('data-copy'))
				btn.textContent = t('copied', 'Copiado')
			})
		})
		result.querySelector('.signdocs-done').addEventListener('click', () => overlay.remove())
	}

	function escapeHtml(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]))
	}

	function registerNewFilesAction() {
		// Nextcloud 28+ Files Vue API
		if (!window.OCA?.Files?.fileActions) {
			return false
		}
		try {
			window.OCA.Files.fileActions.registerAction({
				id: 'signdocs-sign',
				displayName: () => t('action_label', 'Assinar com SignDocs'),
				iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3 17v3h3l11-11-3-3L3 17zm17.7-11.3a1 1 0 0 0 0-1.4l-2-2a1 1 0 0 0-1.4 0l-2 2 3.4 3.4 2-2z"/></svg>`,
				enabled: (nodes) => nodes.length === 1 && supportedMimeTypes.has(nodes[0].mime),
				exec: async (node) => {
					openSigningDialog({ id: node.fileid, name: node.basename, mime: node.mime })
					return null
				},
			})
			return true
		} catch (e) {
			console.warn('SignDocs: registerAction (new API) failed', e)
			return false
		}
	}

	function registerLegacyFilesAction() {
		// Nextcloud 25-27 legacy fileActions
		if (!window.OCA?.Files?.fileActions || !window.OCA.Files.fileActions.registerAction) {
			return false
		}
		supportedMimeTypes.forEach((mime) => {
			OCA.Files.fileActions.registerAction({
				name: 'signdocsSign',
				displayName: t('action_label', 'Assinar com SignDocs'),
				mime,
				permissions: OC.PERMISSION_READ,
				iconClass: 'icon-signdocs',
				actionHandler: (fileName, context) => {
					const fileInfo = context.fileInfoModel
					openSigningDialog({
						id: fileInfo.get('id'),
						name: fileName,
						mime: fileInfo.get('mimetype'),
					})
				},
			})
		})
		return true
	}

	if (!registerNewFilesAction()) {
		document.addEventListener('DOMContentLoaded', registerLegacyFilesAction)
	}
})()
