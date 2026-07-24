<?php

declare(strict_types=1);

require_once __DIR__ . '/../composer/autoload.php';

// nextcloud/ocp ships interface stubs without a composer autoload section, so
// register a PSR-4-ish classloader for the OCP\* and NCU\* namespaces against
// the stub directory.
$ocpRoot = __DIR__ . '/../composer/nextcloud/ocp';
spl_autoload_register(static function (string $class) use ($ocpRoot): void {
	foreach (['OCP\\', 'NCU\\'] as $prefix) {
		if (str_starts_with($class, $prefix)) {
			$relative = substr($class, strlen($prefix));
			$path = $ocpRoot . '/' . substr($prefix, 0, -1) . '/' . str_replace('\\', '/', $relative) . '.php';
			if (is_file($path)) {
				require $path;
			}
			return;
		}
	}
});
