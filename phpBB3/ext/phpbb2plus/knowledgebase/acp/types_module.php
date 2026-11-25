<?php
namespace phpbb2plus\knowledgebase\acp;

use phpbb\request\request_interface;
use phpbb2plus\knowledgebase\service\articles;

class types_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_kb_types';
	public $page_title = 'ACP_PLUS_KB_TYPES';

	private function review($db, $table, $types, $action, $id)
	{
		if ($action !== 'create' && !isset($types[$id])) { throw new \DomainException('ACP_PLUS_KB_TYPE_CONFLICT'); }
		$hash = hash_init('sha256');
		foreach ($types as $type) { hash_update($hash, serialize(array($type['type_id'], $type['title']))); }
		$count = 0;
		if ($action === 'delete')
		{
			$result = $db->sql_query("SELECT article_id,revision FROM $table WHERE type_id=$id ORDER BY article_id");
			while ($row = $db->sql_fetchrow($result)) { hash_update($hash, serialize($row)); ++$count; }
			$db->sql_freeresult($result);
		}
		return array('state' => hash_final($hash), 'count' => $count);
	}

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $table_prefix, $template;
		if (!$auth->acl_gets('a_plus_kb', 'a_plus_kb_types')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang(array('knowledgebase','info_acp_knowledgebase'), 'phpbb2plus/knowledgebase');
		$catalogue = $phpbb_container->get('phpbb2plus.knowledgebase.articles');
		$types = $catalogue->types();
		$articles = $table_prefix . 'plus_kb_articles';
		$table = $table_prefix . 'plus_kb_types';
		$action = $request->variable('action', '');
		$id = $request->variable('type_id', 0);
		if ($action !== '')
		{
			if (!in_array($action, array('create','edit','delete'), true) || ($action === 'create' ? $id !== 0 : $id <= 0))
			{ trigger_error('FORM_INVALID', E_USER_WARNING); }
			$key = 'plus_kb_type_' . $action . '_' . $id;
			$name = $types[$id]['title'] ?? '';
			$error = '';
			try
			{
				if ($request->server('REQUEST_METHOD') === 'POST')
				{
					if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
					$name = trim(htmlspecialchars_decode($request->variable('title', '', true, request_interface::POST), ENT_QUOTES));
					$destination = $request->variable('destination', '', false, request_interface::POST);
					$state = $request->variable('state', '', false, request_interface::POST);
					$editor = $phpbb_container->get('phpbb2plus.knowledgebase.editor');
					$editor->locked(function () use ($catalogue, $db, $articles, $table, $action, $id, $name, $destination, $state)
					{
						$current = $catalogue->types();
						$review = $this->review($db, $articles, $current, $action, $id);
						if (!hash_equals($review['state'], $state)) { throw new \DomainException('ACP_PLUS_KB_TYPE_CONFLICT'); }
						if ($action === 'delete')
						{
							if ($destination === (string) $id || ($destination !== '0' && !isset($current[$destination]))) { throw new \DomainException('ACP_PLUS_KB_TYPE_DEST_INVALID'); }
							$db->sql_query("UPDATE $articles SET type_id=" . (int) $destination . " WHERE type_id=$id");
							$db->sql_query("DELETE FROM $table WHERE type_id=$id");
							return;
						}
						if ($name === '' || utf8_strlen($name) > 255) { throw new \DomainException('ACP_PLUS_KB_TYPE_NAME_INVALID'); }
						$fields = array('title' => articles::encode_label($name), 'label_encoded' => 1);
						if ($action === 'create') { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $fields)); }
						else { $db->sql_query("UPDATE $table SET " . $db->sql_build_array('UPDATE', $fields) . " WHERE type_id=$id"); }
					});
					redirect($this->u_action);
				}
				$review = $this->review($db, $articles, $types, $action, $id);
			}
			catch (\DomainException $e)
			{
				// A changed catalogue needs a freshly opened confirmation with its current destinations.
				if ($e->getMessage() === 'ACP_PLUS_KB_TYPE_CONFLICT' || $e->getMessage() === 'PLUS_KB_CONFLICT')
				{ trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
				$error = $language->lang($e->getMessage());
				$review = $this->review($db, $articles, $types, $action, $id);
			}
			add_form_key($key);
			$template->assign_vars(array('KB_FORM' => true, 'KB_DELETE' => $action === 'delete', 'KB_TYPE_NAME' => $action === 'delete' ? $types[$id]['title'] : $name,
				'KB_LABEL' => $language->lang('ACP_PLUS_KB_TYPE_' . strtoupper($action)), 'KB_STATE' => $review['state'], 'KB_ERROR' => $error,
				'KB_AFFECTED' => $language->lang('ACP_PLUS_KB_TYPE_AFFECTED', $review['count']), 'U_BACK' => $this->u_action,
				'U_SAVE' => $this->u_action . '&amp;action=' . $action . '&amp;type_id=' . $id));
			if ($action === 'delete')
			{
				foreach ($types as $other => $type) { if ($other !== $id) { $template->assign_block_vars('kb_choices', array('ID' => $other, 'TITLE' => $type['title'])); } }
			}
			return;
		}
		$result = $db->sql_query("SELECT type_id,COUNT(*) AS total FROM $articles GROUP BY type_id");
		$counts = array();
		while ($row = $db->sql_fetchrow($result)) { $counts[(int) $row['type_id']] = (int) $row['total']; }
		$db->sql_freeresult($result);
		foreach ($types as $id => $type)
		{
			$url = $this->u_action . '&amp;type_id=' . $id;
			$template->assign_block_vars('kb_types', array('TITLE' => $type['title'], 'TOTAL' => $counts[$id] ?? 0,
				'U_EDIT' => $url . '&amp;action=edit', 'U_DELETE' => $url . '&amp;action=delete'));
		}
		$template->assign_var('U_CREATE', $this->u_action . '&amp;action=create');
	}
}
