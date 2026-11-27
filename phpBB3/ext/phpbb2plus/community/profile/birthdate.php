<?php
namespace phpbb2plus\community\profile;

class birthdate
{
	// Inclusive end dates from the Plus Birthday MOD.
	private const WESTERN = array(120=>'capricorn',219=>'aquarius',320=>'pisces',420=>'aries',520=>'taurus',621=>'gemini',
		722=>'cancer',823=>'leo',922=>'virgo',1022=>'libra',1122=>'scorpio',1221=>'sagittarius',1231=>'capricorn');
	private const ANIMALS = array('rat','buffalo','tiger','cat','dragon','snake','horse','goat','monkey','cock','dog','pig');

	// Gregorian Chinese New Year (MDD), 1901 through 2100, ten years per line.
	// System.Globalization.ChineseLunisolarCalendar.ToDateTime(year,1,1,0,0,0,0);
	// the Hong Kong Observatory publishes the calendar at https://www.hko.gov.hk/en/gts/time/conversion.htm .
	private const NEW_YEAR = array(
		219,208,129,216,204,125,213,202,122,210,
		130,218,206,126,214,203,123,211,201,220,
		208,128,216,205,124,213,202,123,210,130,
		217,206,126,214,204,124,211,131,219,208,
		127,215,205,125,213,202,122,210,129,217,
		206,127,214,203,124,212,131,218,208,128,
		215,205,125,213,202,121,209,130,217,206,
		127,215,203,123,211,131,218,207,128,216,
		205,125,213,202,220,209,129,217,206,127,
		215,204,123,210,131,219,207,128,216,205,
		124,212,201,122,209,129,218,207,126,214,
		203,123,210,131,219,208,128,216,205,125,
		212,201,122,210,129,217,206,126,213,203,
		123,211,131,219,208,128,215,204,124,212,
		201,122,210,130,217,206,126,214,202,123,
		211,201,219,208,128,215,204,124,212,202,
		121,209,129,217,205,126,214,203,123,211,
		131,219,207,127,215,205,124,212,202,122,
		209,129,217,206,126,214,203,124,210,130,
		218,207,127,215,205,125,212,201,121,209,
	);

	/** phpBB stores day-month-year with spaces. Zero is its explicit unspecified year. */
	public static function parts($birthday, \DateTimeImmutable $today)
	{
		if (!preg_match('/^\s*([0-9]{1,2})-\s*([0-9]{1,2})-\s*([0-9]{1,4})$/D', (string) $birthday, $parts)) { return null; }
		$day = (int) $parts[1];
		$month = (int) $parts[2];
		$born = (int) $parts[3];
		if (!checkdate($month, $day, $born ?: 2000) || ($born && $today->setDate($born, $month, $day) > $today)) { return null; }
		return array($day, $month, $born);
	}

	public static function western($month, $day)
	{
		$date = $month * 100 + $day;
		foreach (self::WESTERN as $end => $sign) { if ($date <= $end) { return $sign; } }
		return null;
	}

	public static function chinese($year, $month, $day)
	{
		if ($year < 1901 || $year > 2100) { return null; }
		if ($month * 100 + $day < self::NEW_YEAR[$year - 1901]) { --$year; }
		return self::ANIMALS[($year - 1900) % 12];
	}
}
