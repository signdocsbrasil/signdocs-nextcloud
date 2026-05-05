(function () {
	'use strict'

	const APP_ID = 'signdocs_brasil'
	const root = document.getElementById('signdocs-personal-app')
	if (!root) return

	const state = OCP?.InitialState?.loadState
		? OCP.InitialState.loadState(APP_ID, 'signdocs_personal')
		: { connected: false, signedFolder: '/Assinados', tenantMode: 'oauth' }

	function render() {
		root.innerHTML = state.tenantMode === 'shared'
			? renderShared()
			: state.connected
				? renderConnected()
				: renderDisconnected()

		root.querySelectorAll('[data-action]').forEach((btn) =>
			btn.addEventListener('click', (e) => onAction(e.currentTarget.getAttribute('data-action')))
		)
		const folderInput = root.querySelector('input[name=signedFolder]')
		folderInput?.addEventListener('change', () => saveSignedFolder(folderInput.value))
	}

	function renderConnected() {
		return `
			<div class="signdocs-card">
				<p>✅ Conta SignDocs vinculada.</p>
				<button data-action="disconnect">Desvincular</button>
			</div>
			<label>
				Pasta para PDFs assinados
				<input type="text" name="signedFolder" value="${escapeHtml(state.signedFolder)}" />
			</label>
		`
	}

	function renderDisconnected() {
		return `
			<div class="signdocs-card">
				<p>Nenhuma conta SignDocs vinculada.</p>
				<button data-action="connect" class="primary">Conectar SignDocs Brasil</button>
			</div>
		`
	}

	function renderShared() {
		return `
			<div class="signdocs-card">
				<p>Este Nextcloud está em modo compartilhado — todas as assinaturas são contabilizadas em uma conta SignDocs do tenant configurada pelo administrador.</p>
			</div>
		`
	}

	async function onAction(action) {
		if (action === 'connect') return startDeviceFlow()
		if (action === 'disconnect') return disconnect()
	}

	async function startDeviceFlow() {
		const r = await fetch('/apps/' + APP_ID + '/api/v1/oauth/device', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'requesttoken': OC?.requestToken || '' },
		})
		const data = await r.json()
		if (!r.ok) {
			alert('Erro: ' + (data?.message || 'falha ao iniciar OAuth'))
			return
		}
		alert(`Abra ${data.verification_uri} e digite o código: ${data.user_code}`)
		window.open(data.verification_uri, '_blank', 'noopener')
		// TODO: poll /oauth/poll until completion, then state.connected=true; render()
	}

	async function disconnect() {
		await fetch('/apps/' + APP_ID + '/api/v1/oauth/disconnect', {
			method: 'POST',
			headers: { 'requesttoken': OC?.requestToken || '' },
		})
		state.connected = false
		render()
	}

	async function saveSignedFolder(path) {
		await fetch('/apps/' + APP_ID + '/api/v1/settings/signed-folder', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'requesttoken': OC?.requestToken || '' },
			body: JSON.stringify({ path }),
		})
		state.signedFolder = path
	}

	function escapeHtml(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]))
	}

	render()
})()
