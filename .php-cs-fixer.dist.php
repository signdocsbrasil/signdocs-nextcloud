<?php

declare(strict_types=1);

require_once __DIR__ . '/composer/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('composer')
	->notPath('build')
	->in(__DIR__);

return $config;
