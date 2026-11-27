<?php
namespace phpbb2plus\community\acp;

use phpbb2plus\community\service\post_references;
use phpbb2plus\community\service\account_references;

class post_references_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_post_references';
	public $page_title = 'ACP_PLUS_POST_REFERENCES';

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $template, $phpbb_root_path, $phpEx;
		if (!$auth->acl_get('a_backup')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_post_references', 'phpbb2plus/community');
		$accounts = $mode === 'accounts';
		$sections = $accounts ? array('mailboxes'=>'PLUS_REFS_MAILBOXES','messages'=>'PLUS_REFS_MESSAGES','folders'=>'PLUS_REFS_FOLDERS',
			'memberships'=>'PLUS_REFS_MEMBERSHIPS','defaults'=>'PLUS_REFS_DEFAULTS','profiles'=>'PLUS_REFS_PROFILES') : array('topics'=>'TOPICS','posts'=>'POSTS');
		$section = $request->variable('section', '');
		if (!isset($sections[$section])) { $section = key($sections); }
		foreach ($sections as $key=>$title)
		{
			$template->assign_block_vars('sections', array('CURRENT'=>$key === $section, 'LABEL'=>$language->lang($title),
				'URL'=>$this->u_action . '&amp;section=' . $key));
		}
		$after = $accounts ? $request->variable('after', '') : max(0, $request->variable('after', 0));
		$through = $request->is_set('through') ? ($accounts ? $request->variable('through', '') : max(0, $request->variable('through', 0))) : null;
		$checker = $accounts ? new account_references($db, $phpbb_root_path . 'language', $phpEx) : new post_references($db);
		$page = $checker->page($section, $after, $through);
		$label = static function ($id) use ($accounts, $language, $section) {
			return $accounts ? $language->lang('PLUS_REFS_ID_' . strtoupper($section), ...array_map('intval', explode('.', (string) $id))) : '#' . $id;
		};
		$url = $this->u_action . '&amp;section=' . $section;
		$next = $page['next'] ? $url . '&amp;after=' . rawurlencode((string) $page['next']) . '&amp;through=' . rawurlencode((string) $page['through']) : '';
		$automatic = $next && !$page['findings'] && $request->variable('continue', false);
		if ($automatic) { meta_refresh(2, htmlspecialchars_decode($next . '&amp;continue=1', ENT_QUOTES)); }
		$this->page_title = $accounts ? 'ACP_PLUS_ACCOUNT_REFERENCES' : 'ACP_PLUS_POST_REFERENCES';
		$template->assign_vars(array(
			'PLUS_REPORT_TITLE'=>$language->lang($this->page_title),
			'PLUS_REPORT_HELP'=>$language->lang($section === 'profiles' ? 'PLUS_PROFILE_REFS_HELP' : ($accounts ? 'PLUS_ACCOUNT_REFS_HELP' : 'PLUS_REFS_HELP')),
			'PLUS_RECORD_LABEL'=>$language->lang($accounts ? 'PLUS_REFS_RECORD' : ($section === 'topics' ? 'TOPIC' : 'POST')),
			'PLUS_CHECKED'=>$page['checked'], 'PLUS_AFFECTED'=>count($page['findings']),
			'PLUS_SUMMARY'=>$language->lang('PLUS_REFS_SUMMARY', $page['checked'], count($page['findings'])),
			'PLUS_RANGE'=>$page['checked'] ? $language->lang('PLUS_REFS_RANGE', (int) explode('.', (string) $page['first'])[0],
				(int) explode('.', (string) $page['last'])[0]) : '',
			'U_PLUS_NEXT'=>$next, 'U_PLUS_AUTO'=>$next ? $next . '&amp;continue=1' : '',
			'U_PLUS_STOP'=>$automatic ? $url . '&amp;after=' . rawurlencode((string) $after) . '&amp;through=' . rawurlencode((string) $page['through']) : '',
			'U_PLUS_RESTART'=>$after ? $url : '',
		));
		foreach ($page['findings'] as $id => $issues)
		{
			$template->assign_block_vars('findings', array('ID'=>$id, 'LABEL'=>$label($id)));
			foreach ($issues as $reason => $args)
			{
				$template->assign_block_vars('findings.reasons', array('KEY'=>$reason,
					'TEXT'=>$language->lang('PLUS_REFS_' . strtoupper($reason), ...$args)));
			}
		}
	}
}
