<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\post_icons;

class v111_post_icons extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v110_portal'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'icons'=>array(
			'plus_icon_id'=>array('UINT',0),'plus_icon_level'=>array('UINT',0),'plus_icon_label'=>array('VCHAR:100',''),
			'plus_icon_paused'=>array('BOOL',0))));
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'icons'=>array('plus_icon_id','plus_icon_level','plus_icon_label','plus_icon_paused')));
	}
	public function update_data()
	{
		$changes = array();
		foreach (post_icons::TYPES as $type) { $changes[] = array('config.add',array('plus_icon_' . strtolower(substr($type,5)),0)); }
		$changes[] = array('custom',array(array($this,'install_icons')));
		return $changes;
	}
	public function install_icons()
	{
		$data = post_icons::defaults();
		$icons = $data['icons'];
		foreach ($icons as &$icon)
		{
			$path = $this->phpbb_root_path . 'images/icons/' . $icon['image'];
			$size = is_file($path) ? getimagesize($path) : false;
			if (!$size) { throw new \RuntimeException('Missing bundled post icon: ' . $icon['image']); }
			$icon['width'] = $size[0];
			$icon['height'] = $size[1];
		}
		unset($icon);
		post_icons::import($this->db,$this->config,$this->table_prefix . 'icons',$icons,$data['defaults']);
	}
}
