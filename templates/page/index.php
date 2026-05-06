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
		<p class="signdocs-landing-subtitle">
			<?php p($l->t('Escolha como enviar o documento para assinatura.')); ?>
		</p>
	</header>

	<main class="signdocs-landing-main">
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
		</div>

		<input type="file" id="signdocs-landing-file-input" hidden
			accept=".pdf,.docx,.odt,.doc,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.oasis.opendocument.text,application/msword" />

		<div class="signdocs-landing-status" hidden></div>
	</main>
</div>
