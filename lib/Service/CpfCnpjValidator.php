<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

/**
 * Brazilian fiscal-id validators.
 *
 * Both CPF (individual) and CNPJ (company) carry two check digits computed
 * from the rest of the number; an invalid check-digit pair is enough to
 * reject a fake number 99% of the time. We also reject the all-same-digit
 * pseudo-CPFs (00000000000, 11111111111, ...) which trivially pass the
 * arithmetic but never identify a real person.
 *
 * Real-world non-uniqueness check (e.g. against Receita Federal) is out of
 * scope — that's a paid integration. The arithmetic check matches what
 * Receita's web forms do client-side and what every Brazilian payments
 * library validates.
 */
final class CpfCnpjValidator {

	/**
	 * @param string $digits 11-digit CPF stripped of separators.
	 */
	public static function isValidCpf(string $digits): bool {
		if (strlen($digits) !== 11 || !ctype_digit($digits)) {
			return false;
		}
		// All-same-digit strings pass the modulo arithmetic but are not
		// real CPFs — Receita explicitly blacklists them.
		if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
			return false;
		}

		$d10 = self::computeCheckDigit(substr($digits, 0, 9), startWeight: 10);
		if ($d10 !== (int)$digits[9]) {
			return false;
		}

		$d11 = self::computeCheckDigit(substr($digits, 0, 10), startWeight: 11);
		return $d11 === (int)$digits[10];
	}

	/**
	 * @param string $digits 14-digit CNPJ stripped of separators.
	 */
	public static function isValidCnpj(string $digits): bool {
		if (strlen($digits) !== 14 || !ctype_digit($digits)) {
			return false;
		}
		if (preg_match('/^(\d)\1{13}$/', $digits) === 1) {
			return false;
		}

		// CNPJ uses a non-monotonic weight schedule that cycles 9..2.
		$w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
		$w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

		$d13 = self::computeWeighted(substr($digits, 0, 12), $w1);
		if ($d13 !== (int)$digits[12]) {
			return false;
		}

		$d14 = self::computeWeighted(substr($digits, 0, 13), $w2);
		return $d14 === (int)$digits[13];
	}

	/**
	 * CPF check-digit pattern: weights are a strict-decreasing run from
	 * `startWeight` down to 2, multiplied positionally over the slice.
	 */
	private static function computeCheckDigit(string $slice, int $startWeight): int {
		$sum = 0;
		$len = strlen($slice);
		for ($i = 0; $i < $len; $i++) {
			$sum += ((int)$slice[$i]) * ($startWeight - $i);
		}
		$r = $sum % 11;
		return $r < 2 ? 0 : 11 - $r;
	}

	/**
	 * CNPJ check-digit pattern: weights are an explicit cyclic schedule.
	 *
	 * @param int[] $weights
	 */
	private static function computeWeighted(string $slice, array $weights): int {
		$sum = 0;
		$len = strlen($slice);
		for ($i = 0; $i < $len; $i++) {
			$sum += ((int)$slice[$i]) * $weights[$i];
		}
		$r = $sum % 11;
		return $r < 2 ? 0 : 11 - $r;
	}
}
