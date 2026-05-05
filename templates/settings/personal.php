<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
script('signdocs_brasil', 'signdocs-personal-settings');
style('signdocs_brasil', 'signdocs-files-action');
?>
<div id="signdocs-personal-settings" class="section">
	<h2><?php p($l->t('SignDocs Brasil')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Conecte sua conta SignDocs Brasil para enviar documentos para assinatura direto do Nextcloud.')); ?>
	</p>

	<div id="signdocs-personal-app"></div>
</div>
