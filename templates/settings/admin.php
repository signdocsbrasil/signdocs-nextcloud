<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
script('signdocs_brasil', 'signdocs-admin-settings');
style('signdocs_brasil', 'signdocs-admin-settings');
?>
<div id="signdocs-admin-settings" class="section">
	<h2><?php p($l->t('SignDocs Brasil')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Configure o modo do tenant (OAuth por usuário ou chave compartilhada) e o segredo HMAC do webhook.')); ?>
	</p>
	<p class="settings-hint">
		<?php p($l->t('Importante: para que o convite de assinatura seja enviado por e-mail ao signatário, o usuário do Nextcloud que inicia a assinatura precisa ter um e-mail definido no perfil (Configurações → Informações pessoais). Sem e-mail no perfil, nenhum convite é enviado.')); ?>
	</p>
	<div id="signdocs-admin-app"></div>
</div>
