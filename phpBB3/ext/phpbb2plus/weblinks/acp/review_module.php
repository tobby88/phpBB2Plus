<?php
namespace phpbb2plus\weblinks\acp;

use phpbb\request\request_interface;
use phpbb2plus\weblinks\service\directory;

class review_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container,$request,$user,$template;
		$this->tpl_name = 'acp_weblinks';
		$this->page_title = 'ACP_PLUS_WL';
		$manager = $phpbb_container->get('phpbb2plus.weblinks.directory');
		$language = $phpbb_container->get('language');
		$language->add_lang('directory','phpbb2plus/weblinks');
		$tab = $request->variable('tab',0,false,request_interface::GET);
		$tab = in_array($tab,array(0,1,2),true) ? $tab : 0;
		$url = $this->u_action . '&amp;tab=' . $tab;
		if ($request->is_set_post('decision'))
		{
			if (!check_form_key('plus_wl_review')) { trigger_error('FORM_INVALID',E_USER_WARNING); }
			$choice = $request->variable('decision','',false,request_interface::POST);
			$states = array('approve'=>1,'withdraw'=>0,'decline'=>2);
			if (!isset($states[$choice])) { trigger_error('FORM_INVALID',E_USER_WARNING); }
			try
			{
				$manager->decide($request->variable('link_id',0,false,request_interface::POST),
					$request->variable('revision',0,false,request_interface::POST),$states[$choice]);
			}
			catch (\DomainException $e) { trigger_error($language->lang($e->getMessage()) . adm_back_link($url),E_USER_WARNING); }
			redirect($url);
		}
		list($rows,$total,$start) = $manager->review_page($tab,$request->variable('start',0));
		foreach ($rows as $r)
		{
			$template->assign_block_vars('entries',array('ID'=>(int) $r['link_id'],'REV'=>(int) $r['revision'],
				'TITLE'=>directory::plain($r['title']),'DESCRIPTION'=>directory::plain($r['description']),
				'CATEGORY'=>directory::plain($r['category']),'DEST'=>directory::plain($r['destination']),
				'LOGO'=>directory::plain($r['logo']),'DATE'=>$user->format_date((int) $r['joined'])));
		}
		$template->assign_vars(array('WL_TAB'=>$tab,'U_WL_SAVE'=>$url,'U_WL_PENDING'=>$this->u_action . '&amp;tab=0',
			'U_WL_APPROVED'=>$this->u_action . '&amp;tab=1','U_WL_DECLINED'=>$this->u_action . '&amp;tab=2'));
		$phpbb_container->get('pagination')->generate_template_pagination($url,'pagination','start',$total,25,$start);
		add_form_key('plus_wl_review');
	}
}
