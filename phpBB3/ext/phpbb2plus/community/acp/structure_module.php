<?php
namespace phpbb2plus\community\acp;

class structure_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_structure';
	public $page_title = 'ACP_PLUS_STRUCTURE';

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $template;
		if (!$auth->acl_get('a_backup')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_structure', 'phpbb2plus/community');
		$sections = array(
			'forums'=>array(FORUMS_TABLE, "plus_recovery_reason<>''", 'forum_id', 'forum_type,parent_id,plus_recovery_parent,plus_recovery_reason'),
			'topics'=>array(TOPICS_TABLE, "plus_recovery_reason<>''", 'topic_id', 'forum_id,plus_recovery_from,plus_recovery_reason,plus_recovery_type,topic_visibility'),
			'posts'=>array(POSTS_TABLE, 'plus_recovery_forum>=0', 'post_id', 'forum_id,topic_id,plus_recovery_forum,plus_recovery_topic,post_visibility'),
		);
		$section = $request->variable('section', 'forums');
		if (!isset($sections[$section])) { $section = 'forums'; }
		foreach ($sections as $key=>$query)
		{
			$template->assign_block_vars('sections', array('LABEL'=>$language->lang('ACP_PLUS_STRUCTURE_' . strtoupper($key)),
				'CURRENT'=>$section === $key, 'URL'=>$this->u_action . '&amp;section=' . $key));
		}
		list($table, $where, $id, $fields) = $sections[$section];
		$result = $db->sql_query("SELECT COUNT(*) AS total FROM $table WHERE $where");
		$total = (int) $db->sql_fetchfield('total');
		$db->sql_freeresult($result);
		$pagination = $phpbb_container->get('pagination');
		$limit = 30;
		$start = $pagination->validate_start($request->variable('start', 0), $limit, $total);
		$pagination->generate_template_pagination($this->u_action . '&amp;section=' . $section, 'pagination', 'start', $total, $limit, $start);
		$result = $db->sql_query_limit("SELECT $id,$fields FROM $table WHERE $where ORDER BY $id", $limit, $start);
		while ($row = $db->sql_fetchrow($result))
		{
			$template->assign_block_vars('repairs', $this->describe($section, $row, $language));
		}
		$db->sql_freeresult($result);
	}

	private function describe($section, $row, $language)
	{
		global $auth, $phpbb_root_path, $phpEx;
		$link = '';
		$label = static function ($kind, $number) use ($language) {
			return $language->lang('ACP_PLUS_STRUCTURE_' . $kind, (int) $number);
		};
		if ($section === 'forums')
		{
			$item = $label((int) $row['forum_type'] === FORUM_CAT ? 'CATEGORY' : 'FORUM', $row['forum_id']);
			$previous = $row['plus_recovery_parent'];
			if (preg_match('/^([cf])([0-9]+)$/D', $previous, $match))
			{
				$previous = $label($match[1] === 'c' ? 'CATEGORY' : 'FORUM', $match[2]);
			}
			$current = $label('PARENT', $row['parent_id']);
			if ($auth->acl_get('f_list', (int) $row['forum_id'])) { $link = append_sid($phpbb_root_path . 'viewforum.' . $phpEx, 'f=' . (int) $row['forum_id']); }
		}
		elseif ($section === 'topics')
		{
			$item = $label('TOPIC', $row['topic_id']);
			$previous = $label($row['plus_recovery_reason'] === 'missing-forum' ? 'FORUM' : 'TOPIC', $row['plus_recovery_from']);
			$current = $label('FORUM', $row['forum_id']);
			if ((int) $row['topic_visibility'] === ITEM_APPROVED && $auth->acl_get('f_read', (int) $row['forum_id']))
			{
				$link = append_sid($phpbb_root_path . 'viewtopic.' . $phpEx, 't=' . (int) $row['topic_id']);
			}
			if ((int) $row['plus_recovery_type'] === POST_GLOBAL)
			{
				$previous .= ' (' . $language->lang('ACP_PLUS_STRUCTURE_GLOBAL') . ')';
			}
		}
		else
		{
			$item = $label('POST', $row['post_id']);
			$old_topic = (int) $row['plus_recovery_topic'];
			$previous = $old_topic < 0 ? $label('FORUM', $row['plus_recovery_forum']) :
				$language->lang('ACP_PLUS_STRUCTURE_POSITION', (int) $row['plus_recovery_forum'], $old_topic);
			$current = $language->lang('ACP_PLUS_STRUCTURE_POSITION', (int) $row['forum_id'], (int) $row['topic_id']);
			$row['plus_recovery_reason'] = $old_topic < 0 ? 'post-forum' : 'post-topic';
			if ((int) $row['post_visibility'] === ITEM_APPROVED && $auth->acl_get('f_read', (int) $row['forum_id']))
			{
				$link = append_sid($phpbb_root_path . 'viewtopic.' . $phpEx, 'p=' . (int) $row['post_id']) . '#p' . (int) $row['post_id'];
			}
		}
		$reason = 'ACP_PLUS_STRUCTURE_' . strtoupper(str_replace('-', '_', $row['plus_recovery_reason']));
		return array('ITEM'=>$item, 'URL'=>$link, 'PREVIOUS'=>$previous, 'CURRENT'=>$current, 'REASON'=>$language->lang($reason));
	}
}
