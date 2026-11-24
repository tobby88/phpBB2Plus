<?php
namespace phpbb2plus\arcade\service;

class score
{
	// phpBB's portable decimal column has two fractional digits. Signed fixed-point
	// units preserve all four historical digits and support native numeric sorting.
	static public function units($score)
	{
		if ($score === null || $score === '') { return null; }
		$value = is_float($score) ? sprintf('%.4F', $score) : (string) $score;
		if (!preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,4}))?$/D', $value, $parts))
		{ throw new \UnexpectedValueException('Invalid four-decimal Arcade score.'); }
		$digits = ltrim($parts[2] . str_pad($parts[3] ?? '', 4, '0'), '0');
		return $digits === '' ? '0' : $parts[1] . $digits;
	}

	static public function display($units, $separator = '.')
	{
		$v = (string) $units;
		$digits = str_pad(ltrim($v, '-'), 5, '0', STR_PAD_LEFT);
		$fraction = rtrim(substr($digits, -4), '0');
		return (substr($v, 0, 1) === '-' ? '-' : '') . substr($digits, 0, -4) . ($fraction !== '' ? $separator . $fraction : '');
	}
}
