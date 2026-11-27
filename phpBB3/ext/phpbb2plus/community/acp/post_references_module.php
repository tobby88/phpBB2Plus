<?php
namespace phpbb2plus\community\acp;

use phpbb2plus\community\service\post_references;

class post_references_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_post_references';
	public $page_title = 'ACP_PLUS_POST_REFERENCES';

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $template;
		if (!$auth->acl_get('a_backup')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_post_references', 'phpbb2plus/community');
		$section = $request->variable('section', 'topics') === 'posts' ? 'posts' : 'topics';
		$after = max(0, $request->variable('after', 0));
		$through = $request->is_set('through') ? max(0, $request->variable('through', 0)) : null;
		$page = (new post_references($db))->page($section, $after, $through);
		$url = $this->u_action . '&amp;section=' . $section;
		$next = $page['next'] ? $url . '&amp;after=' . $page['next'] . '&amp;through=' . $page['through'] : '';
		$automatic = $next && !$page['findings'] && $request->variable('continue', false);
		if ($automatic) { meta_refresh(2, htmlspecialchars_decode($next . '&amp;continue=1', ENT_QUOTES)); }
		$template->assign_vars(array(
			'PLUS_SECTION'=>$section, 'PLUS_CHECKED'=>$page['checked'], 'PLUS_AFFECTED'=>count($page['findings']),
			'PLUS_SUMMARY'=>$language->lang('PLUS_REFS_SUMMARY', $page['checked'], count($page['findings'])),
			'PLUS_RANGE'=>$page['checked'] ? $language->lang('PLUS_REFS_RANGE', $page['first'], $page['last']) : '',
			'U_PLUS_TOPICS'=>$this->u_action . '&amp;section=topics', 'U_PLUS_POSTS'=>$this->u_action . '&amp;section=posts',
			'U_PLUS_NEXT'=>$next, 'U_PLUS_AUTO'=>$next ? $next . '&amp;continue=1' : '',
			'U_PLUS_STOP'=>$automatic ? $url . '&amp;after=' . $after . '&amp;through=' . $page['through'] : '',
			'U_PLUS_RESTART'=>$after ? $url : '',
		));
		foreach ($page['findings'] as $id => $issues)
		{
			$template->assign_block_vars('findings', array('ID'=>$id));
			foreach ($issues as $reason => $args)
			{
				$template->assign_block_vars('findings.reasons', array('KEY'=>$reason,
					'TEXT'=>$language->lang('PLUS_REFS_' . strtoupper($reason), ...$args)));
			}
		}
	}
}
