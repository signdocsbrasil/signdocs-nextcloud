<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * Lands an uploaded or remotely-fetched document into the user's Files at a
 * predictable folder, and returns the resulting fileId so the existing
 * signing flow can take over.
 *
 * Default landing folder: `/SignDocs Brasil/Pendentes/`. Created on first
 * use. Filename collisions get a numeric suffix (`contrato.pdf` →
 * `contrato (1).pdf`) so a re-upload doesn't clobber an in-flight session.
 *
 * Stays out of the SigningController so the upload + URL paths can produce
 * a fileId via the same code, and the controller just orchestrates.
 */
class DocumentIntakeService {
	public const DEFAULT_FOLDER = '/SignDocs Brasil/Pendentes';

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * @return array{fileId: int, name: string, path: string}
	 */
	public function storeForCurrentUser(string $filename, string $bytes): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No active user session.');
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$pendingFolder = $this->ensureFolder($userFolder, self::DEFAULT_FOLDER);

		$safeName = $this->sanitizeFilename($filename);
		$uniqueName = $this->resolveUniqueName($pendingFolder, $safeName);

		$file = $pendingFolder->newFile($uniqueName, $bytes);

		return [
			'fileId' => $file->getId(),
			'name' => $file->getName(),
			'path' => $file->getPath(),
		];
	}

	private function ensureFolder(Folder $userFolder, string $relative): Folder {
		$path = ltrim($relative, '/');
		$parts = $path === '' ? [] : explode('/', $path);

		$cursor = $userFolder;
		$walked = '';
		foreach ($parts as $segment) {
			$walked = ltrim($walked . '/' . $segment, '/');
			if ($cursor->nodeExists($segment)) {
				$node = $cursor->get($segment);
				if (!$node instanceof Folder) {
					throw new \RuntimeException(sprintf(
						'Expected folder at /%s but found a file.',
						$walked
					));
				}
				$cursor = $node;
			} else {
				$cursor = $cursor->newFolder($segment);
			}
		}
		return $cursor;
	}

	private function sanitizeFilename(string $name): string {
		// Strip directory traversal + control chars; collapse repeated whitespace.
		$name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
		$name = str_replace(['/', '\\'], '_', $name);
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			$name = 'documento-' . date('Ymd-His') . '.pdf';
		}
		// NC has its own length cap; 240 leaves room for the optional " (N)" suffix.
		if (strlen($name) > 240) {
			$ext = pathinfo($name, PATHINFO_EXTENSION);
			$base = pathinfo($name, PATHINFO_FILENAME);
			$name = substr($base, 0, 240 - strlen($ext) - 1) . '.' . $ext;
		}
		return $name;
	}

	private function resolveUniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		$base = pathinfo($name, PATHINFO_FILENAME);
		$extPart = $ext !== '' ? '.' . $ext : '';

		for ($i = 1; $i < 1000; $i++) {
			$candidate = sprintf('%s (%d)%s', $base, $i, $extPart);
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		// Astronomically unlikely; fall back to a timestamp suffix.
		return sprintf('%s (%s)%s', $base, date('Ymd-His'), $extPart);
	}
}
