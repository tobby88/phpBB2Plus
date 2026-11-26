<?php
namespace phpbb2plus\community;

class ext extends \phpbb\extension\base
{
	private function finish_cli_transition()
	{
		if (PHP_SAPI !== 'cli') { return; }
		// The web ACP flushes caches during core.garbage_collection. The native
		// console ends at console.terminate after the manager has saved ext_active.
		$this->container->get('dispatcher')->addListener(\Symfony\Component\Console\ConsoleEvents::TERMINATE, array($this, 'purge_cli_cache'));
	}

	public function purge_cli_cache()
	{
		$this->container->get('dispatcher')->removeListener(\Symfony\Component\Console\ConsoleEvents::TERMINATE, array($this, __FUNCTION__));
		$this->container->get('cache.driver')->purge();
	}

	public function enable_step($old_state)
	{
		if (parent::enable_step($old_state)) { return true; }
		$db = $this->container->get('dbal.conn');
		$db->sql_transaction('begin');
		try
		{
			$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_type='profilefields.type.plus_multi',plus_paused_type=0
				WHERE plus_paused_type=1 AND field_type='profilefields.type.text'");
			$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET field_no_view=0,plus_paused_view=0 WHERE plus_paused_view=1');
			$this->container->get('profilefields.manager')->enable_profilefields('profilefields.type.plus_multi');
			$this->container->get('notification_manager')->enable_notifications(notification\birthday::TYPE);
			$db->sql_query('UPDATE ' . ICONS_TABLE . ' SET display_on_posting=1,plus_icon_paused=0 WHERE plus_icon_paused=1');
			// Native account removal also works while Community is disabled.
			$table = $this->container->getParameter('core.table_prefix') . 'plus_account_logins';
			$db->sql_query("DELETE FROM $table WHERE NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " u
				WHERE u.user_id=$table.user_id AND u.plus_login_binding=$table.owner_binding AND u.plus_login_binding<>'')");
			$this->container->get('cache.driver')->destroy('_icons');
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
		$this->finish_cli_transition();
		return false;
	}

	public function disable_step($old_state)
	{
		$db = $this->container->get('dbal.conn');
		if (!$this->container->get('dbal.tools')->sql_column_exists(PROFILE_FIELDS_TABLE,'plus_paused_type')) { return false; }
		$db->sql_transaction('begin');
		try
		{
			$this->container->get('profilefields.manager')->disable_profilefields('profilefields.type.plus_multi');
			$this->container->get('notification_manager')->disable_notifications(notification\birthday::TYPE);
			if ($this->container->get('dbal.tools')->sql_column_exists(ICONS_TABLE,'plus_icon_paused'))
			{
				$db->sql_query('UPDATE ' . ICONS_TABLE . ' SET display_on_posting=0,plus_icon_paused=1 WHERE plus_icon_level>0 AND display_on_posting=1');
				$this->container->get('cache.driver')->destroy('_icons');
			}
			// The native new-account default builder also visits inactive fields and needs a registered type.
			$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_type='profilefields.type.text',plus_paused_type=1
				WHERE field_type='profilefields.type.plus_multi'");
			// Native profile pages have no separate visibility flag for fields which only belong in topics.
			$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET field_no_view=1,plus_paused_view=1
				WHERE plus_source_id>0 AND plus_view_profile=0 AND field_no_view=0');
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
		$this->finish_cli_transition();
		return false;
	}

	public function purge_step($old_state)
	{
		if (parent::purge_step($old_state)) { return true; }
		$this->container->get('notification_manager')->purge_notifications(notification\birthday::TYPE);
		return false;
	}
}
