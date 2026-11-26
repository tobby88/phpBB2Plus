<?php
namespace phpbb2plus\downloads\acp;

class checker_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_checker';
	public $page_title = 'ACP_PLUS_DL_CHECKER';

	public function main($id, $mode)
	{
		global $phpbb_container;
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_downloads', 'phpbb2plus/downloads');
		$template = $phpbb_container->get('template');
		if (!$phpbb_container->get('phpbb2plus.downloads.access')->checker_admin()) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		if ($phpbb_container->get('request')->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_dl_checker')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			try { $report = $phpbb_container->get('phpbb2plus.downloads.file_checker')->report(); }
			catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
			foreach ($report['issues'] as $item)
			{
				$template->assign_block_vars('issues', array('KIND'=>$lang->lang('ACP_PLUS_DL_CHECK_' . $item['kind']),
					'ID'=>$item['id'], 'TITLE'=>$item['title'], 'REASON'=>$lang->lang('ACP_PLUS_DL_CHECK_' . $item['reason'])));
			}
			foreach ($report['unreferenced'] as $name=>$bytes)
			{
				$template->assign_block_vars('unreferenced', array('NAME'=>$name, 'SIZE'=>get_formatted_filesize($bytes)));
			}
			$template->assign_vars(array('S_DL_REPORT'=>true, 'DL_TOTAL'=>get_formatted_filesize($report['bytes'])));
		}
		$template->assign_var('U_DL_CHECK', $this->u_action);
		add_form_key('plus_dl_checker');
	}
}
