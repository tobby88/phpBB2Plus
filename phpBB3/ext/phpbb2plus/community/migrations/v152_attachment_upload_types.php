<?php
namespace phpbb2plus\community\migrations;

class v152_attachment_upload_types extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v115_attachment_quotas'); }

	public function update_data()
	{
		// -1 uses the native groups until an administrator chooses a policy or a Plus board is converted.
		return array(array('config.add', array('plus_attach_upload_types', -1)));
	}
}
