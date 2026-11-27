<?php
namespace phpbb2plus\community\profile;

/** The native dropdown stores option_id + 1. The original Plus choices were 0, 1 and 2. */
class gender
{
	public const SETTING = 'plus_gender_profile';
	public const TYPE = 'profilefields.type.dropdown';

	public static function labels($locale)
	{
		return strpos($locale, 'de') === 0 ? array('Geschlecht', 'Keine Angabe', 'Männlich', 'Weiblich') :
			array('Gender', 'None Specified', 'Male', 'Female');
	}

	public static function owner($config)
	{
		if (preg_match('/^([1-9][0-9]*):(plus_gender(?:_[0-9]+)?)$/D', (string) ($config[self::SETTING] ?? ''), $match))
		{ return array((int) $match[1], $match[2]); }
		return null;
	}

	public static function catalogue($db, $config)
	{
		$owner = self::owner($config);
		if (!$owner) { return null; }
		list($id, $ident) = $owner;
		$result = $db->sql_query('SELECT f.field_novalue,o.option_id,o.lang_value,l.lang_iso FROM ' . PROFILE_FIELDS_TABLE . ' f
			LEFT JOIN ' . PROFILE_FIELDS_LANG_TABLE . ' o ON o.field_id=f.field_id
			LEFT JOIN ' . LANG_TABLE . " l ON l.lang_id=o.lang_id WHERE f.field_id=$id AND f.field_ident='$ident' AND f.field_type='" . self::TYPE . "'");
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if (!$rows) { return null; }
		$none = (int) $rows[0]['field_novalue'];
		$meanings = $values = array();
		foreach ($rows as $row)
		{
			if ($row['option_id'] === null || $row['lang_iso'] === null) { continue; }
			$native = (int) $row['option_id'] + 1;
			$labels = self::labels($row['lang_iso']);
			$meaning = array_search($row['lang_value'], array(1=>$labels[2], 2=>$labels[3]), true);
			if ($native === $none || (isset($meanings[$native]) && $meanings[$native] !== $meaning)) { $meaning = false; }
			$meanings[$native] = $meaning;
		}
		foreach ($meanings as $native => $meaning)
		{
			if ($meaning !== false) { $values[$meaning] = isset($values[$meaning]) ? 0 : $native; }
		}
		return array('ident'=>$ident, 'none'=>$none, 'values'=>$values);
	}

	public static function imported_value($source, $catalogue)
	{
		if ((string) $source !== '1' && (string) $source !== '2') { return $catalogue['none']; }
		$value = $catalogue['values'][(int) $source] ?? 0;
		if (!$value)
		{
			throw new \RuntimeException('The destination profile dropdown ' . $catalogue['ident'] .
				' has no unique corresponding Male or Female option. Review its native option translations before retrying conversion.');
		}
		return $value;
	}
}
