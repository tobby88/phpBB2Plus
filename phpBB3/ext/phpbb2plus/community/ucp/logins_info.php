<?php
namespace phpbb2plus\community\ucp;

class logins_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\ucp\logins_module', 'title' => 'UCP_PLUS_LOGINS',
			'modes' => array('history' => array('title' => 'UCP_PLUS_LOGINS', 'auth' => 'ext_phpbb2plus/community', 'cat' => array('UCP_MAIN'))));
	}
}
