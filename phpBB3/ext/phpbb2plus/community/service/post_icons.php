<?php
namespace phpbb2plus\community\service;

class post_icons
{
	public const TYPES = array('POST_ATTACHMENT','POST_PICTURE','POST_CALENDAR','POST_BIRTHDAY','POST_GLOBAL_ANNOUNCE','POST_ANNOUNCE','POST_STICKY','POST_NORMAL');

	public static function defaults()
	{
		static $data;
		if ($data === null)
		{
			$path = dirname(__DIR__) . '/data/post_icons.json';
			$data = is_file($path) ? json_decode(file_get_contents($path),true) : null;
			if (!isset($data['titles']['de'],$data['titles']['en'],$data['icons'],$data['defaults']))
			{
				throw new \RuntimeException('The Plus post icon catalogue is missing from the distribution.');
			}
		}
		return $data;
	}

	/** Keep native IDs stable across interrupted imports. The stock icon pack remains available in the ACP. */
	public static function import($db,$config,$icons_table,array $icons,array $defaults,$conversion=false)
	{
		$native = array();
		$result = $db->sql_query("SELECT icons_id,plus_icon_id FROM $icons_table WHERE plus_icon_id>0");
		while ($row = $db->sql_fetchrow($result)) { $native[(int) $row['plus_icon_id']] = (int) $row['icons_id']; }
		$db->sql_freeresult($result);
		$labels = self::defaults()['titles']['en'];
		$seen = array();
		$db->sql_transaction('begin');
		try
		{
			if ($conversion) { $db->sql_query("UPDATE $icons_table SET display_on_posting=0 WHERE plus_icon_id=0"); }
			foreach ($icons as $index=>$icon)
			{
				$id = (int) $icon['id'];
				$size = max(1, max($icon['width'],$icon['height']) / 127);
				$row = array('plus_icon_id'=>$id, 'plus_icon_level'=>$icon['level'],'plus_icon_label'=>$icon['label'],
					'icons_alt'=>$labels[$icon['label']] ?? $icon['label'],'icons_url'=>$icon['image'],
					'icons_width'=>max(1,(int) round($icon['width']/$size)), 'icons_height'=>max(1,(int) round($icon['height']/$size)),
					'icons_order'=>$index+1, 'display_on_posting'=>(int) ($icon['image'] !== ''));
				if (isset($native[$id]))
				{
					$db->sql_query("UPDATE $icons_table SET " . $db->sql_build_array('UPDATE',$row) . ' WHERE icons_id=' . $native[$id]);
				}
				else
				{
					$db->sql_query("INSERT INTO $icons_table " . $db->sql_build_array('INSERT',$row));
					$native[$id] = (int) $db->sql_nextid();
				}
				$seen[$id] = $native[$id];
			}
			$obsolete = array_diff_key($native,$seen);
			if ($obsolete) { $db->sql_query("DELETE FROM $icons_table WHERE " . $db->sql_in_set('icons_id',array_values($obsolete))); }
			foreach (self::TYPES as $type)
			{
				$config->set('plus_icon_' . strtolower(substr($type,5)),$seen[$defaults[$type] ?? 0] ?? 0);
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	}

	public static function label($key,$native,$locale)
	{
		$titles = self::defaults()['titles'];
		if (!isset($titles['en'][$key]) || $titles['en'][$key] !== $native) { return $native; }
		return $titles[substr($locale,0,2)][$key] ?? $native;
	}
}
