<?php
namespace phpbb2plus\community\acp;

class poll_recovery_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_poll_recovery';
	public $page_title = 'ACP_PLUS_POLL_REVIEW';

	public function main($module, $mode)
	{
		global $auth, $phpbb_container, $request, $template;
		// Held polls can originate in private forums, including ones with no surviving topic.
		if (!$auth->acl_get('a_backup')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_poll_recovery', 'phpbb2plus/community');
		$recovery = $phpbb_container->get('phpbb2plus.community.poll_recovery');
		$pagination = $phpbb_container->get('pagination');
		$limit = 30;
		$url = $this->u_action;
		$reason = static function ($code) use ($language) {
			return $language->lang('ACP_PLUS_POLL_' . strtoupper(str_replace('-', '_', $code)));
		};
		$detail = $request->is_set('poll');
		if ($detail)
		{
			$id = $request->variable('poll', '');
			$header = ctype_digit($id) && (string) (int) $id === $id ? $recovery->header($id) : null;
			if (!$header) { trigger_error($language->lang('ACP_PLUS_POLL_NOT_FOUND') . adm_back_link($url), E_USER_WARNING); }
			$template->assign_vars(array('POLL_DETAIL'=>true, 'U_BACK'=>$url, 'POLL_SOURCE_ID'=>$header['source_id'], 'POLL_TOPIC_ID'=>$header['topic_id'],
				'POLL_REASON'=>$reason($header['reason']), 'POLL_QUESTION'=>$header['question'] === null ? $language->lang('ACP_PLUS_POLL_MISSING_VALUE') :
					html_entity_decode($header['question'], ENT_QUOTES, 'UTF-8'),
				'POLL_START'=>$header['start'], 'POLL_DURATION'=>$header['duration'], 'POLL_RECEIPTS'=>$header['receipts']));
			$url .= '&amp;poll=' . $header['source_id'];
			$count = $header['choices'];
		}
		else { $count = $recovery->count_held(); }
		$start = $pagination->validate_start($request->variable('start', 0), $limit, $count);
		$pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start);
		if ($detail)
		{
			$missing = $language->lang('ACP_PLUS_POLL_MISSING_VALUE');
			foreach ($recovery->choices($header['source_id'], $limit, $start) as $row)
			{
				$template->assign_block_vars('poll_choices', array('ID'=>$row['vote_option_id'] ?? $missing, 'TOTAL'=>$row['vote_result'] ?? $missing,
					'TEXT'=>$row['vote_option_text'] === null ? $missing : html_entity_decode($row['vote_option_text'], ENT_QUOTES, 'UTF-8')));
			}
			return;
		}
		foreach ($recovery->listing($limit, $start) as $row)
		{
			$template->assign_block_vars('held_polls', array('ID'=>$row['source_id'], 'TOPIC'=>$row['topic_id'], 'REASON'=>$reason($row['reason']),
				'DETAIL'=>$url . '&amp;poll=' . $row['source_id']));
		}
	}
}
