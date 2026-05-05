(function () {
	'use strict'

	const APP_ID = 'signdocs_brasil'
	const root = document.getElementById('signdocs-admin-app')
	if (!root) return

	const state = OCP?.InitialState?.loadState
		? OCP.InitialState.loadState(APP_ID, 'signdocs_admin')
		: { tenantMode: 'oauth', tenantConfigured: false, webhookConfigured: false, webhookUrl: '', apiBaseUrl: '' }

	function render() {
		root.innerHTML = `
			<form id="signdocs-admin-form">
				<fieldset>
					<legend>Modo do tenant</legend>
					<label>
						<input type="radio" name="tenantMode" value="oauth" ${state.tenantMode === 'oauth' ? 'checked' : ''} />
						OAuth por usuário (recomendado para auditoria por usuário)
					</label>
					<label>
						<input type="radio" name="tenantMode" value="shared" ${state.tenantMode === 'shared' ? 'checked' : ''} />
						Chave de API compartilhada (todos os usuários assinam pela mesma conta SignDocs)
					</label>
				</fieldset>

				<fieldset id="shared-fields" ${state.tenantMode === 'shared' ? '' : 'hidden'}>
					<legend>Credenciais do tenant</legend>
					<label>Client ID <input type="text" name="clientId" /></label>
					<label>Client Secret <input type="password" name="clientSecret" /></label>
					${state.tenantConfigured ? '<p>✅ Credenciais já configuradas (deixe em branco para manter)</p>' : ''}
				</fieldset>

				<fieldset>
					<legend>Webhook</legend>
					<p>URL para configurar em SignDocs:<br><code>${escapeHtml(state.webhookUrl)}</code></p>
					<label>HMAC Secret <input type="password" name="webhookSecret" /></label>
					${state.webhookConfigured ? '<p>✅ Segredo configurado (deixe em branco para manter)</p>' : ''}
				</fieldset>

				<button type="submit" class="primary">Salvar</button>
				<span id="signdocs-admin-status"></span>
			</form>
		`

		const form = root.querySelector('form')
		form.querySelectorAll('input[name=tenantMode]').forEach((el) =>
			el.addEventListener('change', () => {
				const sharedFields = root.querySelector('#shared-fields')
				sharedFields.hidden = form.tenantMode.value !== 'shared'
			})
		)
		form.addEventListener('submit', onSubmit)
	}

	async function onSubmit(ev) {
		ev.preventDefault()
		const form = ev.target
		const status = root.querySelector('#signdocs-admin-status')
		status.textContent = 'Salvando…'

		const payload = {
			tenantMode: form.tenantMode.value,
		}
		if (form.clientId.value) payload.clientId = form.clientId.value
		if (form.clientSecret.value) payload.clientSecret = form.clientSecret.value
		if (form.webhookSecret.value) payload.webhookSecret = form.webhookSecret.value

		const r = await fetch('/apps/' + APP_ID + '/api/v1/admin/settings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'requesttoken': OC?.requestToken || '' },
			body: JSON.stringify(payload),
		})
		status.textContent = r.ok ? '✅ Salvo' : '❌ Erro'
	}

	function escapeHtml(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]))
	}

	render()
})()
