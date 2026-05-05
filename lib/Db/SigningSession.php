<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Local mirror of a SignDocs signing session.
 *
 * Authoritative state lives in the SignDocs API; this table exists so the NC
 * Files app can render status badges, the Activity stream can resolve session
 * → user → file efficiently, and the polling background job can find pending
 * sessions without a full API list call.
 *
 * @method string getSessionId()
 * @method void setSessionId(string $id)
 * @method string getUserId()
 * @method void setUserId(string $id)
 * @method int getFileId()
 * @method void setFileId(int $id)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 * @method ?string getSignedFileId()
 * @method void setSignedFileId(?string $id)
 * @method ?string getMetadata()
 * @method void setMetadata(?string $metadata)
 */
class SigningSession extends Entity {
	protected string $sessionId = '';
	protected string $userId = '';
	protected int $fileId = 0;
	protected string $status = 'pending';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?string $signedFileId = null;
	protected ?string $metadata = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
