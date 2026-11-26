<?php
namespace phpbb2plus\community\profile;

/** The profile value and its editor belong to phpBB's native dropdown field. */
class country_flags
{
	private $db;
	private $table;
	private $images;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->table = $prefix . 'plus_flags';
	}

	public function field_id()
	{
		$result = $this->db->sql_query("SELECT field_id FROM " . PROFILE_FIELDS_TABLE . " WHERE field_ident='plus_country'");
		$id = (int) $this->db->sql_fetchfield('field_id');
		$this->db->sql_freeresult($result);
		return $id;
	}

	public function replace($flags)
	{
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			$id = $this->field_id();
			if (!$id)
			{
				$result = $db->sql_query('SELECT MAX(field_order) AS last_field FROM ' . PROFILE_FIELDS_TABLE);
				$order = (int) $db->sql_fetchfield('last_field') + 1;
				$db->sql_freeresult($result);
				$definition = array('field_name'=>'plus_country','field_ident'=>'plus_country','field_type'=>'profilefields.type.dropdown',
					'field_length'=>'0','field_minlen'=>'0','field_maxlen'=>'0','field_default_value'=>'1','field_novalue'=>'1',
					'field_active'=>1,'field_show_profile'=>1,'field_show_on_vt'=>1,'field_show_on_pm'=>0,
					'field_show_on_ml'=>0,'field_order'=>$order,'field_validation'=>'');
				$db->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' ' . $db->sql_build_array('INSERT',$definition));
				$id = (int) $db->sql_nextid();
			}
			$db->sql_query('DELETE FROM ' . PROFILE_FIELDS_LANG_TABLE . ' WHERE field_id=' . $id);
			$db->sql_query('DELETE FROM ' . PROFILE_LANG_TABLE . ' WHERE field_id=' . $id);
			$db->sql_query('DELETE FROM ' . $this->table);
			$options = array();
			$images = array();
			foreach ($flags as $i => $flag)
			{
				$number = $i + 2; // The first option is the native no-value choice.
				$label = utf8_encode_ucr(utf8_htmlspecialchars($flag['label']));
				$options[$number - 1] = $label;
				$images[] = array('option_value'=>$number, 'source_id'=>(int) ($flag['source_id'] ?? $i + 1),
					'source_file'=>utf8_encode_ucr($flag['source_file']), 'label'=>$label, 'image'=>$flag['image']);
			}
			foreach (array_chunk($images, 100) as $batch) { $db->sql_multi_insert($this->table, $batch); }
			$result = $db->sql_query('SELECT lang_id,lang_iso FROM ' . LANG_TABLE);
			$languages = $db->sql_fetchrowset($result);
			$db->sql_freeresult($result);
			foreach ($languages as $language)
			{
				$lang_id = (int) $language['lang_id'];
				$german = strpos($language['lang_iso'],'de') === 0;
				$label = array('field_id'=>$id,'lang_id'=>$lang_id,'lang_name'=>$german ? 'Länderflagge' : 'Country flag',
					'lang_explain'=>'','lang_default_value'=>'');
				$db->sql_query('INSERT INTO ' . PROFILE_LANG_TABLE . ' ' . $db->sql_build_array('INSERT',$label));
				$rows = array();
				foreach (array(0 => ($german ? 'Keine Angabe' : 'Unspecified')) + $options as $index => $value)
				{
					$rows[] = array('field_id'=>$id,'lang_id'=>$lang_id,'option_id'=>$index,'field_type'=>'profilefields.type.dropdown','lang_value'=>$value);
				}
				foreach (array_chunk($rows, 100) as $batch) { $db->sql_multi_insert(PROFILE_FIELDS_LANG_TABLE, $batch); }
			}
			$db->sql_transaction('commit');
			$this->images = null;
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	}

	public function image($value, $native_label)
	{
		if ($this->images === null)
		{
			$result = $this->db->sql_query('SELECT * FROM ' . $this->table);
			$this->images = array();
			while ($flag = $this->db->sql_fetchrow($result))
			{
				if ((int) ($flag['catalogue_state'] ?? 0) !== 2) { $this->images[(int) $flag['option_value']] = $flag; }
			}
			$this->db->sql_freeresult($result);
		}
		$flag = $this->images[(int) $value] ?? null;
		// A native administrator can repurpose an option. A flag is only valid for
		// its original label and generated image, otherwise display the native text.
		if (!$flag || $flag['label'] !== $native_label) { return ''; }
		return self::public_image($flag);
	}

	public static function public_image(array $flag)
	{
		$image = (string) $flag['image'];
		if (preg_match('/^[a-f0-9]{64}\.(?:gif|png|jpe?g)$/D', $image)) { return $image; }
		return $image === $flag['source_file'] && self::filename($image) ? $image : '';
	}

	public static function filename($name)
	{
		return strlen($name) <= 25 && preg_match('/^[a-z0-9._-]+\.(?:gif|png|jpe?g)$/iD', $name);
	}
}
