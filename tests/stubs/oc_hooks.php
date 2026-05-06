<?php

// Minimal stubs for symbols referenced by nextcloud/ocp interfaces but not
// shipped by the OCP stub package. Defining them here lets PHPStan resolve
// the surrounding OCP types without pulling in the full server tree.

namespace OC\Hooks {
	interface Emitter {
		public function listen(string $scope, string $method, callable $callback): void;
		public function removeListener(?string $scope = null, ?string $method = null, ?callable $callback = null): void;
	}
}

namespace OCA\Files\Event {
	use OCP\EventDispatcher\Event;

	class LoadAdditionalScriptsEvent extends Event {
	}
}
