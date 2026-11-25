<?php
namespace phpbb2plus\knowledgebase\acp;

use phpbb\request\request_interface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class articles_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_kb_articles';
	public $page_title = 'ACP_PLUS_KB_ARTICLES';

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $table_prefix, $template;
		if (!$auth->acl_gets('a_plus_kb', 'a_plus_kb_articles')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang(array('knowledgebase', 'info_acp_knowledgebase'), 'phpbb2plus/knowledgebase');
		$editor = $phpbb_container->get('phpbb2plus.knowledgebase.editor');
		$private_names = $phpbb_container->has('phpbb2plus.community.former_members') && $phpbb_container->get('phpbb2plus.community.former_members')->hidden();
		$helper = $phpbb_container->get('controller.helper');
		$pagination = $phpbb_container->get('pagination');

		$states = array('edited' => 2, 'pending' => 0, 'published' => 1);
		$filter = $request->variable('status', 'all');
		if (!isset($states[$filter])) { $filter = 'all'; }
		$list = $this->u_action . '&amp;status=' . $filter;
		$start = max(0, $request->variable('start', 0));
		$back = $list . '&amp;start=' . $start;
		$id = $request->variable('article_id', 0);
		$action = $request->variable('action', '');

		if ($action !== '')
		{
			if ($id <= 0 || !in_array($action, array('approve', 'unapprove', 'delete'), true)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$key = 'plus_kb_acp_' . $action . '_' . $id;
			try
			{
				if ($request->server('REQUEST_METHOD') === 'POST')
				{
					if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
					$editor->moderate($action, $id, $request->variable('revision', -1, false, request_interface::POST));
					redirect($back);
				}
				$article = $editor->context($action, $id);
				add_form_key($key);
				$template->assign_vars(array('KB_REVIEW' => true, 'KB_DELETE' => $action === 'delete',
					'KB_TITLE' => $article['title'], 'KB_SUMMARY' => $article['description'], 'KB_REVISION' => $article['revision'],
					'KB_ACTION' => $language->lang('PLUS_KB_ACTION_' . strtoupper($action)), 'U_BACK' => $back,
					'U_SAVE' => $back . '&amp;article_id=' . $id . '&amp;action=' . $action,
					'U_ARTICLE' => $helper->route('phpbb2plus_kb_article', array('article' => $id))));
			}
			catch (NotFoundHttpException $e) { trigger_error($language->lang('PLUS_KB_CONFLICT') . adm_back_link($back), E_USER_WARNING); }
			catch (\DomainException $e) { trigger_error($language->lang($e->getMessage()) . adm_back_link($back), E_USER_WARNING); }
			return;
		}

		$p = $table_prefix . 'plus_kb_';
		$where = isset($states[$filter]) ? ' WHERE a.approval=' . $states[$filter] : '';
		if ($filter === 'pending') { $where = ' WHERE a.approval NOT IN (1,2)'; }
		$result = $db->sql_query("SELECT COUNT(*) AS total FROM {$p}articles a" . $where);
		$count = (int) $db->sql_fetchfield('total');
		$db->sql_freeresult($result);
		$limit = 25;
		$start = $pagination->validate_start($start, $limit, $count);
		$pagination->generate_template_pagination($list, 'pagination', 'start', $count, $limit, $start);
		$template->assign_var('PAGE_NUMBER', $pagination->on_page($count, $limit, $start));
		$names = array_flip($states);
		$result = $db->sql_query_limit("SELECT a.article_id,a.title,a.description,a.approval,a.author_id,a.author_name,a.author_removed,
			c.title AS category,t.title AS article_type,u.username FROM {$p}articles a
			LEFT JOIN {$p}cats c ON c.cat_id=a.cat_id LEFT JOIN {$p}types t ON t.type_id=a.type_id
			LEFT JOIN " . USERS_TABLE . ' u ON u.user_id=a.author_id' . $where .
			' ORDER BY CASE a.approval WHEN 2 THEN 0 WHEN 1 THEN 2 ELSE 1 END, a.article_id DESC', $limit, $start);
		while ($row = $db->sql_fetchrow($result))
		{
			$id = (int) $row['article_id'];
			$approved = (int) $row['approval'] === 1;
			$url = $list . '&amp;start=' . $start . '&amp;article_id=' . $id;
			$author = (int) $row['author_id'] > ANONYMOUS && $row['username'] !== null
				? html_entity_decode($row['username'], ENT_QUOTES, 'UTF-8') : $row['author_name'];
			if ($private_names && $row['author_removed']) { $author = $language->lang('PLUS_KB_FORMER'); }
			$template->assign_block_vars('kb_articles', array('TITLE' => $row['title'], 'SUMMARY' => $row['description'],
				'CATEGORY' => $row['category'], 'TYPE' => $row['article_type'], 'AUTHOR' => $author !== '' ? $author : $language->lang('GUEST'),
				'STATUS' => $language->lang('ACP_PLUS_KB_' . strtoupper($names[(int) $row['approval']] ?? 'pending')),
				'ACTION' => $language->lang($approved ? 'PLUS_KB_ACTION_UNAPPROVE' : 'PLUS_KB_ACTION_APPROVE'),
				'U_ARTICLE' => $helper->route('phpbb2plus_kb_article', array('article' => $id)),
				'U_ACTION' => $url . '&amp;action=' . ($approved ? 'unapprove' : 'approve'), 'U_DELETE' => $url . '&amp;action=delete'));
		}
		$db->sql_freeresult($result);
		foreach (array_merge(array('all'), array_keys($states)) as $name)
		{
			$template->assign_block_vars('kb_filters', array('NAME' => $language->lang('ACP_PLUS_KB_' . strtoupper($name)),
				'ACTIVE' => $name === $filter, 'URL' => $this->u_action . '&amp;status=' . $name));
		}
	}
}
