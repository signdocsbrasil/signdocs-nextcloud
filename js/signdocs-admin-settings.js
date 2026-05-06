(function () {
	'use strict'

	const APP_ID = 'signdocs_brasil'
	const root = document.getElementById('signdocs-admin-app')
	if (!root) return

	const state = OCP?.InitialState?.loadState
		? OCP.InitialState.loadState(APP_ID, 'signdocs_admin')
		: { tenantMode: 'shared', tenantConfigured: false, webhookConfigured: false, webhookUrl: '', apiBaseUrl: '' }

	function render() {
		root.innerHTML = `
			<form id="signdocs-admin-form">
				<div class="signdocs-admin-signup">
					${state.tenantConfigured
						? `Conta SignDocs Brasil configurada. Endpoint: <code>${escapeHtml(state.apiBaseUrl)}</code>`
						: `Não tem uma conta SignDocs Brasil ainda? <a href="https://signdocs.com.br/cadastro" target="_blank" rel="noopener noreferrer">Criar conta gratuita</a> em alguns minutos. Já tem? Pegue suas credenciais de API em Configurações &rarr; API no painel SignDocs.`
					}
				</div>

				<fieldset>
					<legend>Modo do tenant</legend>
					<label>
						<input type="radio" name="tenantMode" value="shared" ${state.tenantMode === 'shared' ? 'checked' : ''} />
						Chave de API compartilhada (todos os usuários assinam pela mesma conta SignDocs) — recomendado para v1
					</label>
					<label>
						<input type="radio" name="tenantMode" value="oauth" ${state.tenantMode === 'oauth' ? 'checked' : ''} disabled />
						OAuth por usuário (em breve na v1.1)
					</label>
				</fieldset>

				<fieldset id="shared-fields" ${state.tenantMode === 'shared' ? '' : 'hidden'}>
					<legend>Credenciais do tenant</legend>
					<label>Client ID <input type="text" name="clientId" autocomplete="off" /></label>
					<label>Client Secret <input type="password" name="clientSecret" autocomplete="off" /></label>
					${state.tenantConfigured ? '<p class="signdocs-admin-hint">Credenciais já configuradas. Deixe em branco para manter os valores atuais.</p>' : ''}
					<div class="signdocs-admin-test-row">
						<button type="button" id="signdocs-admin-test" class="signdocs-admin-test-btn">Testar conexão</button>
						<span id="signdocs-admin-test-result" class="signdocs-admin-test-result" hidden></span>
					</div>
				</fieldset>

				<fieldset>
					<legend>Webhook</legend>
					<p>Configure esta URL no painel SignDocs Brasil em <strong>Webhooks &rarr; Adicionar</strong>:</p>
					<div class="signdocs-admin-webhook-url">
						<input type="text" id="signdocs-webhook-url-display" readonly value="${escapeHtml(state.webhookUrl)}" />
						<button type="button" id="signdocs-webhook-url-copy" class="signdocs-admin-test-btn">Copiar</button>
					</div>
					<label>HMAC Secret <input type="password" name="webhookSecret" autocomplete="off" /></label>
					${state.webhookConfigured ? '<p class="signdocs-admin-hint">Segredo já configurado. Deixe em branco para manter o valor atual.</p>' : ''}
				</fieldset>

				<div class="signdocs-admin-save-row">
					<button type="submit" class="primary">Salvar</button>
					<span id="signdocs-admin-status"></span>
				</div>
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
		document.getElementById('signdocs-admin-test').addEventListener('click', onTest)
		document.getElementById('signdocs-webhook-url-copy').addEventListener('click', onCopyWebhookUrl)
	}

	async function onCopyWebhookUrl(ev) {
		ev.preventDefault()
		const input = document.getElementById('signdocs-webhook-url-display')
		input.select()
		try {
			await navigator.clipboard.writeText(input.value)
			const btn = ev.target
			const original = btn.textContent
			btn.textContent = '✓ Copiado'
			setTimeout(() => { btn.textContent = original }, 1500)
		} catch {
			document.execCommand('copy')
		}
	}

	async function onTest(ev) {
		ev.preventDefault()
		const form = root.querySelector('form')
		const result = document.getElementById('signdocs-admin-test-result')
		const btn = document.getElementById('signdocs-admin-test')
		btn.disabled = true
		result.hidden = false
		result.dataset.kind = 'pending'
		result.textContent = '⏳ Testando...'

		const payload = {}
		if (form.clientId.value) payload.clientId = form.clientId.value
		if (form.clientSecret.value) payload.clientSecret = form.clientSecret.value

		try {
			const r = await fetch('/apps/' + APP_ID + '/api/v1/admin/test-connection', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'requesttoken': OC?.requestToken || '' },
				body: JSON.stringify(payload),
			})
			const data = await r.json()
			if (r.ok && data.ok) {
				result.dataset.kind = 'ok'
				result.textContent = '✅ Conectado a ' + (data.baseUrl || 'SignDocs Brasil')
			} else {
				result.dataset.kind = 'error'
				result.textContent = '❌ ' + (data.message || 'Falha de autenticação')
			}
		} catch (err) {
			result.dataset.kind = 'error'
			result.textContent = '❌ Erro de rede: ' + err.message
		} finally {
			btn.disabled = false
		}
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
