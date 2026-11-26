<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\diagnostics;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class diagnostics_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_arc_diagnostics';
	public $page_title = 'ACP_PLUS_ARC_LOG';

	private function clear_form($filter, $lang, $request, $service, $template, $user)
	{
		$action = $request->variable('operation','',false,request_interface::POST);
		if (!in_array($action,array('selected','filtered'),true)) { throw new \DomainException('FORM_INVALID'); }
		$ids = null;
		if ($action === 'selected')
		{
			$selected = $request->variable('records',array(''),false,request_interface::POST);
			if (!$selected || count($selected)>1000) { throw new \DomainException('PLUS_ARC_LOG_SELECT'); }
			$ids = array();
			foreach ($selected as $value)
			{
				if (!preg_match('/^[1-9][0-9]{0,7}$/D',$value) || (int) $value>16777215) { throw new \DomainException('PLUS_ARC_LOG_SELECT'); }
				$ids[(int) $value] = (int) $value;
			}
			sort($ids,SORT_NUMERIC);
		}
		elseif ($request->is_set_post('records')) { throw new \DomainException('FORM_INVALID'); }
		$confirm = $request->is_set_post('confirm');
		if ($confirm)
		{
			$count = $request->variable('total',0,false,request_interface::POST);
			$last = $request->variable('last',0,false,request_interface::POST);
		}
		else
		{
			if (!check_form_key('plus_arc_log_choose')) { throw new \DomainException('FORM_INVALID'); }
			list($count,$last) = $service->preview($filter,$ids);
			if (!$count) { throw new \DomainException('PLUS_ARC_LOG_NONE'); }
		}
		// Bind the confirmation to the exact filter, record selection, high-water
		// mark and current ACP session. A later arrival belongs to the next review.
		$form = 'plus_arc_log_clear_' . hash('sha256',serialize(array($user->session_id,$filter,$ids,$count,$last)));
		if ($confirm)
		{
			if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
			$service->clear($filter,$ids,$count,$last);
			$template->assign_var('ARC_LOG_NOTICE',$lang->lang('PLUS_ARC_LOG_DONE',$count));
			return false;
		}
		add_form_key($form);
		$template->assign_vars(array('S_ARC_LOG_CONFIRM'=>true,'ARC_LOG_OP'=>$action,'ARC_LOG_IDS'=>$ids ?: array(),
			'ARC_LOG_LAST'=>$last,'ARC_LOG_COUNT'=>$count,'ARC_LOG_FILTER'=>diagnostics::TYPES[$filter] ?: $lang->lang('ALL_ENTRIES'),
			'ARC_LOG_QUESTION'=>$lang->lang($ids === null ? 'PLUS_ARC_LOG_CONFIRM_FILTER' : 'PLUS_ARC_LOG_CONFIRM_SELECTED',$count)));
		return true;
	}

	public function main($id, $mode)
	{
		global $phpbb_container,$request,$template,$user;
		$service = $phpbb_container->get('phpbb2plus.arcade.diagnostics');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_arc_diagnostics','phpbb2plus/arcade');
		$post = $request->server('REQUEST_METHOD') === 'POST';
		$sort = $request->variable('sort','0',false,request_interface::GET);
		$filter = in_array($sort,array('0','1','2','3','4','5'),true) ? (int) $sort : 0;
		$base = $this->u_action . '&amp;sort=' . $filter;
		$requested = max(0,$request->variable('start',0,false,request_interface::GET));
		$template->assign_var('U_ARC_LOG',$base . '&amp;start=' . $requested);
		try
		{
			try
			{
				if ($post)
				{
					if ((string) $filter !== $sort) { throw new \DomainException('FORM_INVALID'); }
					if ($this->clear_form($filter,$lang,$request,$service,$template,$user)) { return; }
				}
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'FORM_INVALID') { send_status_line(403,'Forbidden'); }
				$template->assign_var('ARC_LOG_ERROR',$lang->lang($e->getMessage()));
			}
			list($rows,$total,$per_page,$start) = $service->listing($filter,$requested);
		}
		catch (AccessDeniedHttpException $e) { send_status_line(403,'Forbidden'); trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$pagination = $phpbb_container->get('pagination');
		$pagination->generate_template_pagination($base,'pagination','start',$total,$per_page,$start);
		foreach (diagnostics::TYPES as $n=>$name)
		{
			$template->assign_block_vars('filters',array('NAME'=>$name ?: $lang->lang('ALL_ENTRIES'),'CURRENT'=>$n===$filter,
				'URL'=>$this->u_action . '&amp;sort=' . $n));
		}
		foreach ($rows as $row)
		{
			$author = isset($row['username']) && (int) $row['owner_id']>ANONYMOUS ? catalog::plain($row['username']) :
				$lang->lang((int) $row['user_id']<0 ? 'GUEST' : ((int) $row['user_id']>0 ? 'PLUS_ARC_LOG_NO_ACCOUNT' : 'PLUS_ARC_LOG_UNKNOWN'));
			$template->assign_block_vars('records',array('ID'=>(int) $row['record_no'],'AUTHOR'=>$author,
				'TYPE'=>$row['text_encoded'] ? catalog::plain($row['name']) : (string) $row['name'],
				'TEXT'=>$row['text_encoded'] ? catalog::plain($row['log_value']) : (string) $row['log_value'],
				'DATE'=>$row['recorded_at'] ? $user->format_date((int) $row['recorded_at']) : $lang->lang('PLUS_ARC_LOG_UNKNOWN')));
		}
		add_form_key('plus_arc_log_choose');
		$template->assign_vars(array('U_ARC_LOG'=>$base . '&amp;start=' . $start,'ARC_LOG_TOTAL'=>$total,
			'PAGE_NUMBER'=>$pagination->on_page($total,$per_page,$start)));
	}
}
