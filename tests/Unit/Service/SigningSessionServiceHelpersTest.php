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
	/** @var \ReflectionMethod */
	private \ReflectionMethod $validateOptions;
	/** @var \ReflectionMethod */
	private \ReflectionMethod $validateSigners;
	/** @var SigningSessionService */
	private SigningSessionService $stub;

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SigningSessionService::class);

		$this->mapModeToProfile = $ref->getMethod('mapModeToProfile');
		$this->mapModeToProfile->setAccessible(true);

		$this->buildSigner = $ref->getMethod('buildSigner');
		$this->buildSigner->setAccessible(true);

		$this->validateOptions = $ref->getMethod('validateOptions');
		$this->validateOptions->setAccessible(true);

		$this->validateSigners = $ref->getMethod('validateSigners');
		$this->validateSigners->setAccessible(true);

		// Construct without calling __construct (skips dependency wiring).
		$this->stub = $ref->newInstanceWithoutConstructor();
	}

	public function testMapModeToProfileMapsDigitalCertificate(): void {
		// 'digital_certificate' is the canonical input the UI sends.
		self::assertSame('DIGITAL_CERTIFICATE', $this->mapModeToProfile->invoke($this->stub, 'digital_certificate'));
	}

	public function testMapModeToProfileKeepsLegacyIcpAliasesForRollingDeploys(): void {
		// During a rolling rollout, an older NC client may still submit
		// 'icp_a1' / 'icp_a3' from a stale JS bundle. Keep them mapping to
		// the same profile so requests don't fail mid-deploy.
		self::assertSame('DIGITAL_CERTIFICATE', $this->mapModeToProfile->invoke($this->stub, 'icp_a1'));
		self::assertSame('DIGITAL_CERTIFICATE', $this->mapModeToProfile->invoke($this->stub, 'icp_a3'));
	}

	public function testMapModeToProfileMapsBiometricToBiometric(): void {
		self::assertSame('BIOMETRIC', $this->mapModeToProfile->invoke($this->stub, 'biometric'));
	}

	public function testMapModeToProfileMapsClickPlusOtp(): void {
		self::assertSame('CLICK_PLUS_OTP', $this->mapModeToProfile->invoke($this->stub, 'click_plus_otp'));
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
		self::assertNull($signer->cnpj);
		self::assertSame('+5511999999999', $signer->phone);
	}

	public function testBuildSignerRoutesCnpjSeparately(): void {
		$signer = $this->buildSigner->invoke($this->stub, [
			'name' => 'Empresa ABC Ltda',
			'email' => 'fiscal@abc.com.br',
			'cnpj' => '12345678000190',
		], 0);
		self::assertNull($signer->cpf);
		self::assertSame('12345678000190', $signer->cnpj);
	}

	public function testBuildSignerKeepsCpfAndCnpjMutuallyExclusiveWhenEmpty(): void {
		// Empty strings on either side must NOT propagate as the SDK rejects
		// a Signer that carries an empty doc id.
		$signer = $this->buildSigner->invoke($this->stub, [
			'name' => 'No-doc',
			'email' => 'nodoc@example.com',
			'cpf' => '',
			'cnpj' => '',
		], 0);
		self::assertNull($signer->cpf);
		self::assertNull($signer->cnpj);
	}

	public function testBuildSignerHandlesMissingNameGracefully(): void {
		// Real validation lives in the controller; the helper itself should
		// not blow up on an empty name (just produces an empty-name Signer).
		$signer = $this->buildSigner->invoke($this->stub, ['email' => 'x@y.com'], 0);
		self::assertSame('', $signer->name);
		self::assertSame('x@y.com', $signer->email);
	}

	public function testValidateOptionsRejectsIcpWithParallelMultiSigner(): void {
		// 2+ signers + ICP digital_certificate + PARALLEL → must throw
		// before we ever ask the SignDocs API.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Digital certificate signing requires sequential order with multiple signers.');
		$this->validateOptions->invoke($this->stub, 'digital_certificate', 'PARALLEL', 2);
	}

	public function testValidateOptionsRejectsLegacyIcpAliasesToo(): void {
		// Defense-in-depth: a stale client still sending icp_a1/icp_a3 from
		// before we consolidated the dropdown must hit the same constraint.
		$this->expectException(\InvalidArgumentException::class);
		$this->validateOptions->invoke($this->stub, 'icp_a1', 'PARALLEL', 3);
	}

	public function testValidateOptionsAllowsIcpWithSequential(): void {
		// Happy path — must NOT throw.
		$this->validateOptions->invoke($this->stub, 'digital_certificate', 'SEQUENTIAL', 5);
		self::assertTrue(true); // explicit pass; no exception means valid
	}

	public function testValidateOptionsAllowsIcpWithSingleSigner(): void {
		// 1 signer → order is meaningless, ICP is fine even with PARALLEL
		// (the service won't actually create an envelope for 1 signer).
		$this->validateOptions->invoke($this->stub, 'digital_certificate', 'PARALLEL', 1);
		self::assertTrue(true);
	}

	public function testValidateOptionsAllowsNonIcpModesWithParallel(): void {
		// CLICK_ONLY and CLICK_PLUS_OTP are unconstrained by this rule.
		$this->validateOptions->invoke($this->stub, 'electronic', 'PARALLEL', 5);
		$this->validateOptions->invoke($this->stub, 'click_plus_otp', 'PARALLEL', 5);
		self::assertTrue(true);
	}

	public function testValidateSignersAcceptsValidCpfAndCnpj(): void {
		$this->validateSigners->invoke($this->stub, [
			['name' => 'Maria', 'email' => 'maria@example.com', 'cpf' => '12345678909'],
			['name' => 'Empresa ABC', 'email' => 'fiscal@abc.com.br', 'cnpj' => '12345678000195'],
		]);
		self::assertTrue(true);
	}

	public function testValidateSignersRejectsMissingFiscalId(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Signer #2 is missing a CPF or CNPJ.');
		$this->validateSigners->invoke($this->stub, [
			['name' => 'Maria', 'email' => 'm@x.com', 'cpf' => '12345678909'],
			['name' => 'João', 'email' => 'j@x.com'], // no cpf, no cnpj
		]);
	}

	public function testValidateSignersRejectsInvalidCpfCheckDigits(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Signer #1 has an invalid CPF.');
		$this->validateSigners->invoke($this->stub, [
			['name' => 'Bad', 'email' => 'b@x.com', 'cpf' => '12345678900'],
		]);
	}

	public function testValidateSignersRejectsAllSameDigitCpf(): void {
		// All-same-digit CPFs satisfy the arithmetic but Receita rejects them.
		$this->expectException(\InvalidArgumentException::class);
		$this->validateSigners->invoke($this->stub, [
			['name' => 'Bad', 'email' => 'b@x.com', 'cpf' => '11111111111'],
		]);
	}

	public function testValidateSignersRejectsInvalidCnpj(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Signer #1 has an invalid CNPJ.');
		$this->validateSigners->invoke($this->stub, [
			['name' => 'Empresa', 'email' => 'e@x.com', 'cnpj' => '12345678000100'],
		]);
	}

	public function testValidateSignersIdentifiesTheSpecificFailingSigner(): void {
		// First two valid, third invalid → error must say "#3" so the
		// front-end can highlight the right row.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Signer #3 has an invalid CPF.');
		$this->validateSigners->invoke($this->stub, [
			['name' => 'A', 'email' => 'a@x.com', 'cpf' => '12345678909'],
			['name' => 'B', 'email' => 'b@x.com', 'cnpj' => '12345678000195'],
			['name' => 'C', 'email' => 'c@x.com', 'cpf' => '11111111111'],
		]);
	}

	public function testSignedFileNameStripsExtensionAndAppendsSuffix(): void {
		self::assertSame('Contrato-assinado.pdf', SigningSessionService::signedFileName('Contrato.pdf'));
		self::assertSame('Contrato-assinado.pdf', SigningSessionService::signedFileName('Contrato.docx'));
		self::assertSame('Meu.Contrato.v2-assinado.pdf', SigningSessionService::signedFileName('Meu.Contrato.v2.odt'));
	}

	public function testSignedFileNameFallsBackWhenOriginalMissing(): void {
		self::assertSame('documento-assinado.pdf', SigningSessionService::signedFileName(null));
		self::assertSame('documento-assinado.pdf', SigningSessionService::signedFileName(''));
	}
}
