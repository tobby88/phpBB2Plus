<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;

class portal_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;
	private const LIMITS = array('recent'=>100,'last_seen'=>100,'top_posters'=>100,'shouts'=>2,'stats'=>1);

	private function snapshot($db, $text, $lock)
	{
		$keys = array_map(static function ($name) { return 'plus_portal_' . $name; },array_keys(self::LIMITS));
		$result = $db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $db->sql_in_set('config_name',$keys) .
			' ORDER BY config_name' . ($lock ? ' FOR UPDATE' : ''));
		$values = array();
		while ($r = $db->sql_fetchrow($result)) { $values[$r['config_name']] = (int) $r['config_value']; }
		$db->sql_freeresult($result);
		$values += $text->get_array(array('plus_portal_welcome','plus_portal_exclude','plus_portal_poll_forums'));
		ksort($values);
		return $values;
	}

	public function main($id, $mode)
	{
		global $phpbb_container;
		$db = $phpbb_container->get('dbal.conn');
		$config = $phpbb_container->get('config');
		$text = $phpbb_container->get('config_text');
		$template = $phpbb_container->get('template');
		$language = $phpbb_container->get('language');
		$request = $phpbb_container->get('request');
		$language->add_lang('community','phpbb2plus/community');
		$this->tpl_name = 'acp_plus_portal';
		$this->page_title = 'ACP_PLUS_PORTAL';
		$key = 'phpbb2plus_community_portal_settings';
		add_form_key($key);
		$old = $this->snapshot($db,$text,false);
		if ($request->is_set_post('save'))
		{
			if (!check_form_key($key)) { trigger_error('FORM_INVALID',E_USER_WARNING); }
			$welcome = htmlspecialchars_decode($request->variable('welcome','',true,request_interface::POST),ENT_QUOTES);
			if (strlen($welcome) > 65535) { trigger_error('PLUS_PORTAL_LENGTH',E_USER_WARNING); }
			$values = array();
			foreach (self::LIMITS as $name=>$max)
			{
				$raw = $request->variable($name,'',false,request_interface::POST);
				if (!ctype_digit($raw) || (int) $raw > $max) { trigger_error('PLUS_PORTAL_LIMIT',E_USER_WARNING); }
				$values['plus_portal_' . $name] = (int) $raw;
			}
			$long = array('plus_portal_welcome'=>$welcome);
			$selectable = get_forum_list('f_list',true,true);
			foreach (array('exclude','poll_forums') as $name)
			{
				$stored = array_filter(explode(',',(string) $old['plus_portal_' . $name]));
				$chosen = $request->variable($name,array(0),false,request_interface::POST);
				$ids = array_unique(array_merge(array_intersect($selectable,$chosen),array_diff($stored,$selectable)));
				sort($ids,SORT_NUMERIC);
				$long['plus_portal_' . $name] = implode(',',$ids);
			}
			$db->sql_transaction('begin');
			try
			{
				$revision = $request->variable('revision','',false,request_interface::POST);
				if (!hash_equals(hash('sha256',serialize($this->snapshot($db,$text,true))),$revision)) { throw new \DomainException('PLUS_PORTAL_CHANGED'); }
				foreach ($values as $name=>$value) { $config->set($name,$value); }
				$text->set_array($long);
				$db->sql_transaction('commit');
			}
			catch (\Throwable $e)
			{
				$db->sql_transaction('rollback');
				if ($e instanceof \DomainException) { trigger_error($e->getMessage(),E_USER_WARNING); }
				throw $e;
			}
			$user = $phpbb_container->get('user');
			$phpbb_container->get('log')->add('admin',$user->data['user_id'],$user->ip,'LOG_PLUS_PORTAL_SETTINGS');
			trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		$template->assign_vars(array('U_SAVE'=>$this->u_action,'REVISION'=>hash('sha256',serialize($old)),
			'WELCOME_TEXT'=>utf8_htmlspecialchars((string) $old['plus_portal_welcome']),
			'SHOUTS'=>(int) $old['plus_portal_shouts'],'STATS'=>(int) $old['plus_portal_stats']));
		foreach (array('recent','last_seen','top_posters') as $name)
		{
			$template->assign_block_vars('counts',array('NAME'=>$name,'LABEL'=>$language->lang('PLUS_PORTAL_' . strtoupper($name)),
				'VALUE'=>(int) $old['plus_portal_' . $name]));
		}
		$forums = get_forum_list('f_list',false,true);
		foreach (array('exclude','poll_forums') as $name)
		{
			$selected = explode(',',(string) $old['plus_portal_' . $name]);
			$template->assign_block_vars('lists',array('NAME'=>$name,'LABEL'=>$language->lang('PLUS_PORTAL_' . strtoupper($name))));
			foreach ($forums as $row)
			{
				$template->assign_block_vars('lists.options',array('ID'=>(int) $row['forum_id'],'TITLE'=>$row['padding'] . $row['forum_name'],
					'SELECTED'=>in_array((string) $row['forum_id'],$selected,true)));
			}
		}
	}
}
