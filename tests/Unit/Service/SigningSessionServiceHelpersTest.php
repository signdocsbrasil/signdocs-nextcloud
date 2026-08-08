<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Service\SigningSessionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SignDocsBrasil\Api\Models\Owner;
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
	/** @var \ReflectionMethod */
	private \ReflectionMethod $validateDocumentFormat;
	/** @var \ReflectionMethod */
	private \ReflectionMethod $buildShareLink;
	/** @var \ReflectionMethod */
	private \ReflectionMethod $validateInviteDeliverable;
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

		$this->validateDocumentFormat = $ref->getMethod('validateDocumentFormat');
		$this->validateDocumentFormat->setAccessible(true);

		$this->buildShareLink = $ref->getMethod('buildShareLink');
		$this->buildShareLink->setAccessible(true);

		$this->validateInviteDeliverable = $ref->getMethod('validateInviteDeliverable');
		$this->validateInviteDeliverable->setAccessible(true);

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
		// CLICK_ONLY and CLICK_PLUS_OTP are always parallel — the happy path.
		$this->validateOptions->invoke($this->stub, 'electronic', 'PARALLEL', 5);
		$this->validateOptions->invoke($this->stub, 'click_plus_otp', 'PARALLEL', 5);
		self::assertTrue(true);
	}

	public function testValidateOptionsRejectsSequentialWithoutIcp(): void {
		// The dialog only offers sequential order under digital_certificate;
		// a click/OTP envelope has no signature chain to order. A stale client
		// still asking for it must be rejected, not silently honoured.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Sequential order requires digital certificate signing.');
		$this->validateOptions->invoke($this->stub, 'electronic', 'SEQUENTIAL', 3);
	}

	public function testValidateOptionsRejectsSequentialWithOtpMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Sequential order requires digital certificate signing.');
		$this->validateOptions->invoke($this->stub, 'click_plus_otp', 'SEQUENTIAL', 2);
	}

	public function testValidateOptionsAllowsSequentialWithSingleNonIcpSigner(): void {
		// 1 signer never becomes an envelope, so the order field is inert —
		// no reason to fail the request over it.
		$this->validateOptions->invoke($this->stub, 'electronic', 'SEQUENTIAL', 1);
		self::assertTrue(true);
	}

	public function testValidateOptionsAllowsSequentialForLegacyIcpAliases(): void {
		// icp_a1 / icp_a3 still map to the certificate profile, so they keep
		// access to sequential order.
		$this->validateOptions->invoke($this->stub, 'icp_a1', 'SEQUENTIAL', 3);
		$this->validateOptions->invoke($this->stub, 'icp_a3', 'SEQUENTIAL', 3);
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

	public function testSignedFileNameKeepsNativeFormatForTheCadesPair(): void {
		// A non-PDF signed with an ICP-Brasil certificate is saved back as the
		// original format plus a detached .p7s — the two share a basename so the
		// pair is obvious in the Files list.
		self::assertSame('Contrato-assinado.docx', SigningSessionService::signedFileName('Contrato.docx', 'docx'));
		self::assertSame('Contrato-assinado.p7s', SigningSessionService::signedFileName('Contrato.docx', 'p7s'));
		self::assertSame('Contrato-assinado.odt', SigningSessionService::signedFileName('Contrato.odt', '.odt'));
	}

	public function testFileExtensionIsLowercasedAndDotless(): void {
		self::assertSame('docx', SigningSessionService::fileExtension('Contrato.DOCX'));
		self::assertSame('pdf', SigningSessionService::fileExtension('a/b/Contrato.pdf'));
		self::assertSame('', SigningSessionService::fileExtension('Contrato'));
		self::assertSame('', SigningSessionService::fileExtension(null));
		self::assertSame('', SigningSessionService::fileExtension(''));
	}

	public function testValidateDocumentFormatRejectsNonPdfWithoutCertificate(): void {
		// Interim gate: a click/OTP signature on a non-PDF produces no artifact
		// the app could ever save back, so the request is refused up front.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Non-PDF documents require digital certificate signing.');
		$this->validateDocumentFormat->invoke($this->stub, "PK\x03\x04docx-bytes", 'electronic');
	}

	public function testValidateDocumentFormatAllowsNonPdfWithCertificate(): void {
		$this->validateDocumentFormat->invoke($this->stub, "PK\x03\x04docx", 'digital_certificate');
		$this->validateDocumentFormat->invoke($this->stub, "PK\x03\x04docx", 'icp_a1');
		self::assertTrue(true);
	}

	public function testValidateDocumentFormatAllowsAnyModeForAPdf(): void {
		$this->validateDocumentFormat->invoke($this->stub, '%PDF-1.7 ...', 'electronic');
		$this->validateDocumentFormat->invoke($this->stub, '%PDF-1.7 ...', 'click_plus_otp');
		self::assertTrue(true);
	}

	public function testValidateDocumentFormatSniffsContentNotTheFilename(): void {
		// The API decides the format from the bytes, so a mislabelled PDF must
		// not be gated — otherwise we'd reject a request the API would accept.
		$this->validateDocumentFormat->invoke($this->stub, '%PDF-1.4 mislabelled as .docx', 'electronic');
		self::assertTrue(true);
	}

	public function testIsDigitalCertificateModeCoversLegacyAliases(): void {
		// Only these modes produce a detached CAdES signature for a non-PDF, so
		// this gate decides whether the save-back job has anything to wait for.
		self::assertTrue(SigningSessionService::isDigitalCertificateMode('digital_certificate'));
		self::assertTrue(SigningSessionService::isDigitalCertificateMode('icp_a1'));
		self::assertTrue(SigningSessionService::isDigitalCertificateMode('icp_a3'));
		self::assertFalse(SigningSessionService::isDigitalCertificateMode('electronic'));
		self::assertFalse(SigningSessionService::isDigitalCertificateMode('click_plus_otp'));
	}

	public function testCanonicalStatusMapsTerminalStates(): void {
		self::assertSame('completed', SigningSessionService::canonicalStatus('COMPLETED'));
		self::assertSame('completed', SigningSessionService::canonicalStatus('ALL_SIGNED'));
		self::assertSame('cancelled', SigningSessionService::canonicalStatus('CANCELLED'));
		self::assertSame('expired', SigningSessionService::canonicalStatus('EXPIRED'));
		self::assertSame('failed', SigningSessionService::canonicalStatus('FAILED'));
	}

	public function testCanonicalStatusMapsNonTerminalToPendingSoRowStaysPolled(): void {
		// Envelope CREATED/ACTIVE (and any unknown/in-progress) must normalise to
		// 'pending' — otherwise the row drops out of findPendingOlderThan and is
		// never reconciled to completion.
		self::assertSame('pending', SigningSessionService::canonicalStatus('ACTIVE'));
		self::assertSame('pending', SigningSessionService::canonicalStatus('CREATED'));
		self::assertSame('pending', SigningSessionService::canonicalStatus('PENDING'));
		self::assertSame('pending', SigningSessionService::canonicalStatus('IN_PROGRESS'));
	}

	/**
	 * @param array<string, mixed> $signerData
	 * @return array<string, mixed>
	 */
	private function shareLink(string $profile, array $signerData, ?Owner $owner, string $url = 'https://sign.test/s/1', string $secret = 'ss_secret_abc'): array {
		return $this->buildShareLink->invoke(
			null,
			$profile,
			$signerData,
			'ss_1',
			$url,
			$secret,
			true,
			$owner,
		);
	}

	public function testClickOnlyLinkIsWithheldFromAThirdParty(): void {
		// The whole point: a CLICK_ONLY URL is a bearer credential, so it is
		// never written down for the sender to pass along.
		$entry = $this->shareLink(
			'CLICK_ONLY',
			['email' => 'maria@example.com', 'name' => 'Maria'],
			new Owner(email: 'owner@example.com', name: 'Owner'),
		);

		self::assertArrayNotHasKey('url', $entry);
		self::assertFalse($entry['shareable']);
		self::assertSame('maria@example.com', $entry['signerEmail']);
	}

	public function testClickOnlyLinkSurvivesForTheSenderSigningTheirOwnDocument(): void {
		// SignDocs skips the invite when the addresses match, so withholding
		// this one would leave nobody able to sign.
		$entry = $this->shareLink(
			'CLICK_ONLY',
			['email' => 'owner@example.com', 'name' => 'Owner'],
			new Owner(email: 'owner@example.com', name: 'Owner'),
		);

		self::assertTrue($entry['shareable']);
		self::assertSame('https://sign.test/s/1?cs=ss_secret_abc', $entry['url']);
	}

	public function testTheSelfSignerMatchIgnoresCaseAndSurroundingSpace(): void {
		// The front end decides the same thing with JS toLowerCase(); a
		// byte-wise compare here would promise a link and then not render one.
		$entry = $this->shareLink(
			'CLICK_ONLY',
			['email' => '  Owner@Example.COM '],
			new Owner(email: 'owner@example.com', name: 'Owner'),
		);

		self::assertTrue($entry['shareable']);
	}

	public function testSecondFactorProfilesKeepTheirLinks(): void {
		$owner = new Owner(email: 'owner@example.com', name: 'Owner');
		foreach (['CLICK_PLUS_OTP', 'DIGITAL_CERTIFICATE', 'BIOMETRIC'] as $profile) {
			$entry = $this->shareLink($profile, ['email' => 'maria@example.com'], $owner);
			self::assertTrue($entry['shareable'], $profile . ' should be shareable');
			self::assertArrayHasKey('url', $entry, $profile . ' should carry a url');
		}
	}

	public function testAnUnknownProfileIsTreatedAsUnshareable(): void {
		// The allowlist is what makes this fail closed: a profile added to the
		// API later is withheld until somebody reviews it.
		$entry = $this->shareLink(
			'SOME_FUTURE_PROFILE',
			['email' => 'maria@example.com'],
			new Owner(email: 'owner@example.com', name: 'Owner'),
		);

		self::assertFalse($entry['shareable']);
		self::assertArrayNotHasKey('url', $entry);
	}

	public function testNoOwnerMeansNoSelfSignerCarveOut(): void {
		$entry = $this->shareLink('CLICK_ONLY', ['email' => 'maria@example.com'], null);

		self::assertFalse($entry['shareable']);
		self::assertArrayNotHasKey('url', $entry);
	}

	public function testAnEmptyUrlOrSecretNeverBecomesARelativeLink(): void {
		// The SDK defaults both to '' when the API omits them, and '' . '?cs=…'
		// is a relative URL the browser would render as a working link.
		$owner = new Owner(email: 'owner@example.com', name: 'Owner');

		$noUrl = $this->shareLink('CLICK_PLUS_OTP', ['email' => 'maria@example.com'], $owner, '', 'ss_secret_abc');
		self::assertArrayNotHasKey('url', $noUrl);

		$noSecret = $this->shareLink('CLICK_PLUS_OTP', ['email' => 'maria@example.com'], $owner, 'https://sign.test/s/1', '');
		self::assertArrayNotHasKey('url', $noSecret);
	}

	/** @param array<int, array<string, mixed>> $shareLinks */
	private function metadata(array $shareLinks): string {
		return json_encode(['kind' => 'envelope', 'shareLinks' => $shareLinks], JSON_THROW_ON_ERROR);
	}

	public function testHasOwnSignatureFindsTheCallersOwnEntry(): void {
		$meta = $this->metadata([
			['sessionId' => 'ss_a', 'signerEmail' => 'maria@example.com', 'shareable' => false],
			['sessionId' => 'ss_b', 'signerEmail' => 'Owner@Example.com', 'shareable' => true],
		]);

		self::assertTrue(SigningSessionService::hasOwnSignature($meta, 'owner@example.com'));
	}

	public function testHasOwnSignatureIgnoresOtherPeoplesEntries(): void {
		// The point of the whole feature: this must not become a way to mint
		// somebody else's link.
		$meta = $this->metadata([
			['sessionId' => 'ss_a', 'signerEmail' => 'maria@example.com', 'shareable' => true],
		]);

		self::assertFalse(SigningSessionService::hasOwnSignature($meta, 'owner@example.com'));
	}

	public function testHasOwnSignatureRefusesAWithheldEntryEvenIfItIsYours(): void {
		// Can't happen through createForFile — your own entry is always
		// shareable — but the predicate must not be the weak link if it does.
		$meta = $this->metadata([
			['sessionId' => 'ss_a', 'signerEmail' => 'owner@example.com', 'shareable' => false],
		]);

		self::assertFalse(SigningSessionService::hasOwnSignature($meta, 'owner@example.com'));
	}

	public function testHasOwnSignatureTreatsLegacyRowsAsWithheld(): void {
		// Rows written before the flag existed carry a url and no `shareable`.
		// Fail closed rather than mint from a row whose policy we can't confirm.
		$meta = $this->metadata([
			['sessionId' => 'ss_a', 'signerEmail' => 'owner@example.com', 'url' => 'https://sign.test/s/a?cs=x'],
		]);

		self::assertFalse(SigningSessionService::hasOwnSignature($meta, 'owner@example.com'));
	}

	public function testHasOwnSignatureNeedsAnAddressAndASessionId(): void {
		$meta = $this->metadata([
			['sessionId' => '', 'signerEmail' => 'owner@example.com', 'shareable' => true],
		]);

		self::assertFalse(SigningSessionService::hasOwnSignature($meta, 'owner@example.com'));
		self::assertFalse(SigningSessionService::hasOwnSignature($meta, null));
		self::assertFalse(SigningSessionService::hasOwnSignature($meta, ''));
		self::assertFalse(SigningSessionService::hasOwnSignature(null, 'owner@example.com'));
		self::assertFalse(SigningSessionService::hasOwnSignature('not json', 'owner@example.com'));
	}

	public function testClickOnlyIsRefusedWithoutAProfileEmail(): void {
		// Nobody would be emailed and no link would be shown — a document
		// created, charged for, and unsignable.
		$this->expectException(\InvalidArgumentException::class);
		$this->validateInviteDeliverable->invoke(null, 'CLICK_ONLY', '');
	}

	public function testASecondFactorProfileNeedsNoProfileEmail(): void {
		// The OTP reaches the signer from the signing page itself, so an
		// ownerless send is still usable from a manually pasted link.
		$this->validateInviteDeliverable->invoke(null, 'CLICK_PLUS_OTP', null);
		$this->validateInviteDeliverable->invoke(null, 'DIGITAL_CERTIFICATE', '');
		$this->validateInviteDeliverable->invoke(null, 'CLICK_ONLY', 'owner@example.com');
		self::assertTrue(true);
	}
}
