<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
script('signdocs_brasil', 'signdocs-landing-page');
script('signdocs_brasil', 'signdocs-files-action');
style('signdocs_brasil', 'signdocs-files-action');
style('signdocs_brasil', 'signdocs-landing-page');
?>
<div id="signdocs-app">
	<header class="signdocs-landing-header">
		<h1><?php p($l->t('Solicitar assinaturas')); ?></h1>
	</header>

	<main class="signdocs-landing-main">

		<!-- Two views: the four entry points, and the request list. Only one is
		     ever visible; landing-page.js swaps them. -->
		<div class="signdocs-view" data-view="home">
			<p class="signdocs-landing-subtitle">
				<?php p($l->t('Escolha como enviar o documento para assinatura.')); ?>
			</p>

			<div class="signdocs-landing-actions">
			<button type="button" class="signdocs-landing-button" data-action="pick-from-files">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">
					<path fill="currentColor" d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
				</svg>
				<span class="signdocs-landing-button-label">
					<?php p($l->t('Escolher dos meus arquivos')); ?>
				</span>
				<small><?php p($l->t('Documentos já no seu Nextcloud')); ?></small>
			</button>

			<button type="button" class="signdocs-landing-button" data-action="upload-local">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">
					<path fill="currentColor" d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/>
				</svg>
				<span class="signdocs-landing-button-label">
					<?php p($l->t('Enviar do meu computador')); ?>
				</span>
				<small><?php p($l->t('Arquivo do disco local')); ?></small>
			</button>

			<button type="button" class="signdocs-landing-button" data-action="upload-from-url">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">
					<path fill="currentColor" d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/>
				</svg>
				<span class="signdocs-landing-button-label">
					<?php p($l->t('Buscar de uma URL')); ?>
				</span>
				<small><?php p($l->t('Link público para um arquivo')); ?></small>
				</button>

				<button type="button" class="signdocs-landing-button" data-action="show-requests">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">
						<path fill="currentColor" d="M3 5h18v2H3V5zm0 6h18v2H3v-2zm0 6h12v2H3v-2z"/>
					</svg>
					<span class="signdocs-landing-button-label">
						<?php p($l->t('Suas solicitações de assinatura')); ?>
					</span>
					<small><?php p($l->t('Acompanhar status e cancelar')); ?></small>
				</button>
			</div>
		</div>

		<div class="signdocs-view" data-view="requests" hidden>
			<header class="signdocs-requests-header">
				<button type="button" class="signdocs-requests-back">
					&larr; <?php p($l->t('Voltar')); ?>
				</button>
				<h2><?php p($l->t('Suas solicitações de assinatura')); ?></h2>
				<button type="button" class="signdocs-requests-refresh">
					<?php p($l->t('Atualizar')); ?>
				</button>
			</header>
			<p class="signdocs-requests-empty" hidden>
				<?php p($l->t('Você ainda não enviou nenhum documento para assinatura.')); ?>
			</p>
			<div class="signdocs-requests-scroll">
				<ul class="signdocs-requests-list"></ul>
			</div>
		</div>

		<input type="file" id="signdocs-landing-file-input" hidden
			accept=".pdf,.docx,.odt,.doc,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.oasis.opendocument.text,application/msword" />

		<div class="signdocs-landing-status" hidden></div>

	</main>
</div>
