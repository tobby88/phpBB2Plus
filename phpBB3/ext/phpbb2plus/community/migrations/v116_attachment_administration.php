<?php
namespace phpbb2plus\community\migrations;

class v116_attachment_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v115_attachment_quotas'); }

	public function update_data()
	{
		// Retain the effective mailbox allowance when upgrading a previously converted board.
		$base = empty($this->config['plus_attach_quotas']) ? '0' : (string) $this->config['max_filesize_pm'];
		return array(array('config.add',array('plus_attach_base_pm',$base)),
			array('config.add',array('plus_attach_revision','0',true)),
			array('config.add',array('plus_attach_last_level','0',true)),
			array('module.add',array('acp','ACP_ATTACHMENTS',array(
				'module_basename'=>'\phpbb2plus\community\acp\quotas_module','modes'=>array('manage')))));
	}
}
