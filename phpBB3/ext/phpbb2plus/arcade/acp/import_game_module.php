<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class import_game_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_arc_import';
	public $page_title = 'ACP_PLUS_ARC_IMPORT';

	public function main($id,$mode)
	{
		global $phpbb_container,$request,$template;
		$service = $phpbb_container->get('phpbb2plus.arcade.batch_import');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_arc_games','phpbb2plus/arcade');
		$key = $request->variable('batch','',false,request_interface::GET);
		$template->assign_var('U_ARC_IMPORT_LIST',$this->u_action);
		try
		{
			if ($key==='')
			{
				$start = max(0,$request->variable('start',0));
				$list = $service->listing($start);
				foreach (array_slice($list,0,25) as $batch)
				{
					$template->assign_block_vars('batches',array('LABEL'=>catalog::plain($batch['label']),
						'KIND'=>$lang->lang('PLUS_ARC_IMPORT_' . strtoupper($batch['kind'])),'COUNT'=>(int) $batch['item_count'],
						'ADDED'=>(int) $batch['added'],'POSITION'=>(int) $batch['position'],
						'URL'=>$this->u_action . '&amp;batch=' . $batch['batch_key']));
				}
				$template->assign_vars(array('U_ARC_NEXT'=>count($list)>25 ? $this->u_action . '&amp;start=' . ($start+25) : '',
					'U_ARC_PREV'=>$start>0 ? $this->u_action . '&amp;start=' . max(0,$start-25) : ''));
				return;
			}
			$form = 'plus_arc_import_' . $key;
			$url = $this->u_action . '&amp;batch=' . $key;
			$advanced = false;
			if ($request->server('REQUEST_METHOD')==='POST')
			{
				if (!check_form_key($form)) { send_status_line(403,'Forbidden'); trigger_error('FORM_INVALID',E_USER_WARNING); }
				$input = array();
				foreach (array('category','online','sizing') as $field) { $input[$field] = $request->variable($field,'',false,request_interface::POST); }
				try
				{
					$advanced = $service->apply($key,$request->variable('position',-1,false,request_interface::POST),$input);
				}
				catch (\DomainException $e) { $template->assign_var('ARC_ERROR',$lang->lang($e->getMessage())); }
			}
			$row = $service->batch($key);
			$position = (int) $row['position'];
			$cats = $service->categories();
			$replacement = $row['settings'] && $row['settings']['category']!==-1 && !isset($cats[$row['settings']['category']]);
			foreach (array(-1=>$lang->lang('PLUS_ARC_EDIT_UNCATEGORISED'))+$cats as $cat=>$name)
			{ $template->assign_block_vars('cats',array('ID'=>$cat,'NAME'=>$name)); }
			foreach ($row['items'] as $item)
			{ $template->assign_block_vars('files',array('NAME'=>catalog::plain($item['source_name']))); }
			$template->assign_vars(array('S_BATCH'=>true,'ARC_LABEL'=>catalog::plain($row['label']),'ARC_KIND'=>$lang->lang('PLUS_ARC_IMPORT_' . strtoupper($row['kind'])),
				'ARC_STATUS'=>$lang->lang('PLUS_ARC_IMPORT_STATUS',$position,(int) $row['item_count'],(int) $row['added'],(int) $row['scan_skipped']+$position-(int) $row['added']),
				'ARC_POSITION'=>$position,'S_COMPLETE'=>$position>=(int) $row['item_count'],'S_STARTED'=>(bool) $row['settings'],
				'S_RESELECT'=>$replacement,'S_CONTINUE'=>$advanced && !$replacement,'U_ARC_IMPORT'=>$url));
			add_form_key($form);
		}
		catch (AccessDeniedHttpException $e) { send_status_line(403,'Forbidden'); trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()),E_USER_WARNING); }
	}
}
