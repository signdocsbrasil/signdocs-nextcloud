<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
script('signdocs_brasil', 'signdocs-admin-settings');
?>
<div id="signdocs-admin-settings" class="section">
	<h2><?php p($l->t('SignDocs Brasil')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Configure o modo do tenant (OAuth por usuário ou chave compartilhada) e o segredo HMAC do webhook.')); ?>
	</p>
	<div id="signdocs-admin-app"></div>
</div>
