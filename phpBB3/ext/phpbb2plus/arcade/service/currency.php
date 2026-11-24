<?php
namespace phpbb2plus\arcade\service;

/** Exact decimal balances, including unsigned BIGINT and DECIMAL source columns. */
class currency
{
	static public function scaled($value, $scale)
	{
		if (!is_string($value) || $scale < 0 || $scale > 38 || strlen($value) > 255 ||
			!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]{1,3}))?$/D', $value, $m))
		{ throw new \DomainException('PLUS_ARC_WALLET'); }
		$digits = $m[2] . ($m[3] ?? '');
		$at = strlen($m[2]) + (int) ($m[4] ?? 0) + $scale;
		if ($at > 220) { throw new \DomainException('PLUS_ARC_WALLET'); }
		if ($at < 0) { return '0'; }
		$digits = str_pad($digits, $at + 1, '0');
		$whole = ltrim(substr($digits, 0, $at), '0') ?: '0';
		if ($digits[$at] >= '5') { $whole = self::add($whole, '1'); }
		return $m[1] === '-' && $whole !== '0' ? '-' . $whole : $whole;
	}

	static public function amount($digits, $scale)
	{
		$sign = substr($digits, 0, 1) === '-' ? '-' : '';
		$digits = str_pad(ltrim($digits, '-'), $scale + 1, '0', STR_PAD_LEFT);
		if (!$scale) { return $sign . $digits; }
		$fraction = rtrim(substr($digits, -$scale), '0');
		return $sign . substr($digits, 0, -$scale) . ($fraction !== '' ? '.' . $fraction : '');
	}

	static public function add($a, $b)
	{
		$negative = $a[0] === '-';
		$subtract = $negative !== ($b[0] === '-');
		$a = ltrim($a, '-');
		$b = ltrim($b, '-');
		if (strlen($b) > strlen($a) || (strlen($a) === strlen($b) && strcmp($b, $a) > 0))
		{
			list($a, $b) = array($b, $a);
			if ($subtract) { $negative = !$negative; }
		}
		$result = '';
		$carry = 0;
		for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--)
		{
			$other = $j >= 0 ? (int) $b[$j] : 0;
			$digit = (int) $a[$i] + ($subtract ? -$other : $other) + $carry;
			$carry = $digit < 0 ? -1 : ($digit > 9 ? 1 : 0);
			$result = (string) (($digit + 10) % 10) . $result;
		}
		if ($carry > 0) { $result = '1' . $result; }
		$result = ltrim($result, '0') ?: '0';
		if (strlen($result) > 220) { throw new \DomainException('PLUS_ARC_WALLET'); }
		return $negative && $result !== '0' ? '-' . $result : $result;
	}

	// Source rewards divide the integer part of the score and add any record bonuses.
	// Long division keeps all eight configured fractional digits at large divisors.
	static public function reward($units, $divisor, $bonus)
	{
		$amount = self::scaled((string) $bonus, 8);
		if ($divisor > 0)
		{
			$whole = substr(str_pad(ltrim($units, '-'), 5, '0', STR_PAD_LEFT), 0, -4);
			$remainder = 0;
			$digits = '';
			foreach (str_split($whole . '000000000') as $digit)
			{
				$part = $remainder * 10 + (int) $digit;
				$q = (int) floor($part / $divisor);
				$remainder = $part - $q * $divisor;
				$digits .= (string) $q;
			}
			$result = ltrim(substr($digits, 0, -1), '0') ?: '0';
			if (substr($digits, -1) >= '5') { $result = self::add($result, '1'); }
			if ($units[0] === '-' && $result !== '0') { $result = '-' . $result; }
			$amount = self::add($amount, $result);
		}
		return self::amount($amount, 8);
	}
}
