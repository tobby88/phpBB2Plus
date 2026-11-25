<?php
namespace phpbb2plus\community\migrations;

class v113_portal_media extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v112_byline_search'); }
	public function update_data()
	{
		$data = array();
		foreach (array('downloads'=>5,'pictures'=>1,'picture_size'=>100,'picture_personal'=>0,'picture_random'=>0) as $name=>$value)
		{
			$data[] = array('config.add',array('plus_portal_' . $name,$value));
		}
		$data[] = array('config_text.add',array('plus_portal_picture_categories',''));
		return $data;
	}
}
