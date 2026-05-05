<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Service\SigningSessionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SignDocsBrasil\Api\Models\Signer;

/**
 * Pinning behavior of the two pure helper methods inside SigningSessionService:
 *  - mapModeToProfile() — UI mode keys → SDK profile constants
 *  - buildSigner()      — derives a stable userExternalId from email
 *
 * Tested via reflection because both are private. They're pure, deterministic,
 * and small enough that the safety of "test the public path" doesn't apply —
 * a regression in either would break every signing session this app creates.
 */
class SigningSessionServiceHelpersTest extends TestCase {
	/** @var \ReflectionMethod */
	private \ReflectionMethod $mapModeToProfile;
	/** @var \ReflectionMethod */
	private \ReflectionMethod $buildSigner;
	/** @var SigningSessionService */
	private SigningSessionService $stub;

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SigningSessionService::class);

		$this->mapModeToProfile = $ref->getMethod('mapModeToProfile');
		$this->mapModeToProfile->setAccessible(true);

		$this->buildSigner = $ref->getMethod('buildSigner');
		$this->buildSigner->setAccessible(true);

		// Construct without calling __construct (skips dependency wiring).
		$this->stub = $ref->newInstanceWithoutConstructor();
	}

	public function testMapModeToProfileMapsIcpVariantsToDigitalCertificate(): void {
		self::assertSame('DIGITAL_CERTIFICATE', $this->mapModeToProfile->invoke($this->stub, 'icp_a1'));
		self::assertSame('DIGITAL_CERTIFICATE', $this->mapModeToProfile->invoke($this->stub, 'icp_a3'));
	}

	public function testMapModeToProfileMapsBiometricToBiometric(): void {
		self::assertSame('BIOMETRIC', $this->mapModeToProfile->invoke($this->stub, 'biometric'));
	}

	public function testMapModeToProfileFallsBackToClickOnly(): void {
		self::assertSame('CLICK_ONLY', $this->mapModeToProfile->invoke($this->stub, 'electronic'));
		self::assertSame('CLICK_ONLY', $this->mapModeToProfile->invoke($this->stub, 'unknown_mode'));
		self::assertSame('CLICK_ONLY', $this->mapModeToProfile->invoke($this->stub, ''));
	}

	public function testBuildSignerDerivesStableExternalIdFromEmail(): void {
		$signer = $this->buildSigner->invoke($this->stub, [
			'name' => 'Maria Silva',
			'email' => 'maria@example.com',
		], 0);

		self::assertInstanceOf(Signer::class, $signer);
		self::assertSame('Maria Silva', $signer->name);
		self::assertSame('maria@example.com', $signer->email);

		$expected = 'nc:' . hash('sha256', 'maria@example.com');
		self::assertSame($expected, $signer->userExternalId);
	}

	public function testBuildSignerNormalizesEmailCaseForExternalId(): void {
		// Re-sending to "Maria@Example.COM" must produce the same externalId
		// as "maria@example.com" so SignDocs treats them as the same person.
		$lower = $this->buildSigner->invoke($this->stub, ['name' => 'M', 'email' => 'maria@example.com'], 0);
		$upper = $this->buildSigner->invoke($this->stub, ['name' => 'M', 'email' => 'Maria@Example.COM'], 5);
		self::assertSame($lower->userExternalId, $upper->userExternalId);
	}

	public function testBuildSignerFallsBackToIndexedIdWhenNoEmail(): void {
		$signer = $this->buildSigner->invoke($this->stub, ['name' => 'John'], 3);
		self::assertSame('nc:idx:3', $signer->userExternalId);
	}

	public function testBuildSignerFallsBackToIndexedIdWhenEmailIsEmpty(): void {
		$signer = $this->buildSigner->invoke($this->stub, ['name' => 'John', 'email' => ''], 7);
		self::assertSame('nc:idx:7', $signer->userExternalId);
	}

	public function testBuildSignerPropagatesOptionalFields(): void {
		$signer = $this->buildSigner->invoke($this->stub, [
			'name' => 'Maria',
			'email' => 'maria@example.com',
			'cpf' => '12345678901',
			'phone' => '+5511999999999',
		], 0);
		self::assertSame('12345678901', $signer->cpf);
		self::assertSame('+5511999999999', $signer->phone);
	}

	public function testBuildSignerHandlesMissingNameGracefully(): void {
		// Real validation lives in the controller; the helper itself should
		// not blow up on an empty name (just produces an empty-name Signer).
		$signer = $this->buildSigner->invoke($this->stub, ['email' => 'x@y.com'], 0);
		self::assertSame('', $signer->name);
		self::assertSame('x@y.com', $signer->email);
	}
}
