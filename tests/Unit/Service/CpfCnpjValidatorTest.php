<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Service\CpfCnpjValidator;
use PHPUnit\Framework\TestCase;

/**
 * Receita Federal's CPF and CNPJ check-digit algorithms, encoded as test
 * vectors. Real check-digit math, not a placeholder — getting this wrong
 * would let invalid fiscal ids reach the SignDocs API where they'd 422
 * mid-flow and waste the caller's time.
 *
 * Vectors verified by hand against the official spec:
 *  - CPF: sum d_i * (10..2) for first 9 digits, modulo 11, 11-r unless
 *    r<2 (in which case 0). Same pattern with weights 11..2 for the
 *    second check digit.
 *  - CNPJ: explicit cyclic weight schedule [5,4,3,2,9,8,7,6,5,4,3,2]
 *    for the first 12 digits / first check digit, prepended with 6 for
 *    the second check digit's 13-digit slice.
 */
class CpfCnpjValidatorTest extends TestCase {
	// CPF "12345678909" — derived check digits are 0 and 9.
	// Worked example:
	//   sum = 1·10+2·9+3·8+4·7+5·6+6·5+7·4+8·3+9·2 = 210
	//   210 % 11 = 1 → r<2 → d10 = 0
	//   sum2 = 1·11+2·10+3·9+4·8+5·7+6·6+7·5+8·4+9·3+0·2 = 255
	//   255 % 11 = 2 → r>=2 → d11 = 11-2 = 9
	private const CPF_VALID_1 = '12345678909';

	// CPF "11144477735" — a commonly-cited valid example for testing.
	private const CPF_VALID_2 = '11144477735';

	// CNPJ "12345678000195" — derived from 12-digit base 123456780001.
	private const CNPJ_VALID = '12345678000195';

	public function testValidCpfPasses(): void {
		self::assertTrue(CpfCnpjValidator::isValidCpf(self::CPF_VALID_1));
		self::assertTrue(CpfCnpjValidator::isValidCpf(self::CPF_VALID_2));
	}

	public function testCpfWithWrongCheckDigitsFails(): void {
		// Flip the last digit on a valid CPF.
		self::assertFalse(CpfCnpjValidator::isValidCpf('12345678900'));
		self::assertFalse(CpfCnpjValidator::isValidCpf('11144477734'));
	}

	public function testCpfWithWrongLengthFails(): void {
		self::assertFalse(CpfCnpjValidator::isValidCpf(''));
		self::assertFalse(CpfCnpjValidator::isValidCpf('123'));
		self::assertFalse(CpfCnpjValidator::isValidCpf('1234567890'));   // 10 digits
		self::assertFalse(CpfCnpjValidator::isValidCpf('123456789090')); // 12 digits
	}

	public function testCpfWithNonDigitsFails(): void {
		self::assertFalse(CpfCnpjValidator::isValidCpf('123.456.789-09'));
		self::assertFalse(CpfCnpjValidator::isValidCpf('1234567890a'));
	}

	public function testCpfAllSameDigitsRejectedDespiteValidArithmetic(): void {
		// All-same-digit CPFs satisfy the arithmetic but Receita blacklists them.
		foreach (['00000000000', '11111111111', '22222222222', '99999999999'] as $cpf) {
			self::assertFalse(
				CpfCnpjValidator::isValidCpf($cpf),
				"All-same-digit CPF $cpf must be rejected"
			);
		}
	}

	public function testValidCnpjPasses(): void {
		self::assertTrue(CpfCnpjValidator::isValidCnpj(self::CNPJ_VALID));
	}

	public function testCnpjWithWrongCheckDigitsFails(): void {
		self::assertFalse(CpfCnpjValidator::isValidCnpj('12345678000100'));
		self::assertFalse(CpfCnpjValidator::isValidCnpj('12345678000196'));
	}

	public function testCnpjWithWrongLengthFails(): void {
		self::assertFalse(CpfCnpjValidator::isValidCnpj(''));
		self::assertFalse(CpfCnpjValidator::isValidCnpj('123'));
		self::assertFalse(CpfCnpjValidator::isValidCnpj('1234567800019'));    // 13 digits
		self::assertFalse(CpfCnpjValidator::isValidCnpj('123456780001955'));  // 15 digits
	}

	public function testCnpjAllSameDigitsRejected(): void {
		foreach (['00000000000000', '11111111111111', '99999999999999'] as $cnpj) {
			self::assertFalse(
				CpfCnpjValidator::isValidCnpj($cnpj),
				"All-same-digit CNPJ $cnpj must be rejected"
			);
		}
	}

	public function testCnpjWithNonDigitsFails(): void {
		self::assertFalse(CpfCnpjValidator::isValidCnpj('12.345.678/0001-95'));
	}
}
