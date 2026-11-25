<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;

class categories_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_categories';
	public $page_title = 'ACP_PLUS_DL_CATEGORIES';

	private function link($action, $id = 0)
	{
		return $this->u_action . '&amp;action=' . $action . ($id ? '&amp;category=' . $id : '');
	}

	private function input($request, $row, $posted)
	{
		$form = array('title'=>access::plain($row['title'] ?? ''),'summary'=>access::plain($row['summary'] ?? ''),'parent'=>(int) ($row['parent_id'] ?? 0),
			'allow_files'=>(bool) ($row['allow_files'] ?? true),'allow_ratings'=>(bool) ($row['allow_ratings'] ?? true),
			'allow_comments'=>(bool) ($row['allow_comments'] ?? true),'initial_access'=>'private',
			'files_mode'=>'','children_mode'=>'','files_to'=>-1,'children_to'=>-1,'confirm'=>false);
		if (!$posted) { return $form; }
		foreach ($form as $key=>$value)
		{
			if (is_bool($value)) { $form[$key] = $request->variable($key,0,false,request_interface::POST) === 1; }
			elseif (is_int($value)) { $form[$key] = $request->variable($key,-1,false,request_interface::POST); }
			else { $form[$key] = htmlspecialchars_decode($request->variable($key,'',true,request_interface::POST),ENT_QUOTES); }
		}
		return $form;
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$access = $phpbb_container->get('phpbb2plus.downloads.access');
		if (!$access->catalogue_admin()) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_downloads','phpbb2plus/downloads');
		$catalogue = $phpbb_container->get('phpbb2plus.downloads.catalogue');
		$tree = $catalogue->tree();
		$action = $request->variable('action','');
		$id = $request->variable('category',0);
		if ($action === '')
		{
			$counts = $catalogue->counts();
			foreach ($tree['list'] as $key=>$depth)
			{
				$row = $tree['rows'][$key];
				$siblings = $tree['children'][(int) $row['parent_id']];
				$parent = (int) $row['parent_id'];
				$parent_name = !$parent ? $language->lang('ACP_PLUS_DL_ROOT') : access::plain($tree['rows'][$parent]['title'] ?? '');
				$template->assign_block_vars('dl_categories', array('ID'=>$key,'TITLE'=>access::plain($row['title']),'DEPTH'=>max(0,$depth),
					'PARENT_NAME'=>$parent_name,'INVALID'=>$depth<0,'FILES'=>$counts[$key] ?? 0,'U_EDIT'=>$this->link('edit',$key),'U_DELETE'=>$this->link('delete',$key),
					'U_UP'=>$key === $siblings[0] ? '' : $this->link('up',$key),'U_DOWN'=>$key === end($siblings) ? '' : $this->link('down',$key)));
			}
			$template->assign_var('U_DL_CREATE',$this->link('create'));
			return;
		}
		if (!in_array($action,array('create','edit','up','down','delete'),true) || ($action === 'create' ? $id !== 0 : !isset($tree['rows'][$id])))
		{ trigger_error('FORM_INVALID', E_USER_WARNING); }
		$key = 'plus_dl_catalogue_' . $action . '_' . $id;
		$posted = $request->server('REQUEST_METHOD') === 'POST';
		$form = $this->input($request,$tree['rows'][$id] ?? array(),$posted);
		$error = '';
		try
		{
			if ($posted)
			{
				if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				$catalogue->change($action,$id,$request->variable('state','',false,request_interface::POST),$form);
				redirect($this->u_action);
			}
			$review = $catalogue->review($tree,$action,$id);
		}
		catch (\DomainException $e)
		{
			if (!$posted || in_array($e->getMessage(),array('ACP_PLUS_DL_CATALOGUE_CHANGED','NO_AUTH_OPERATION'),true))
			{ trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
			$error = $language->lang($e->getMessage());
			$tree = $catalogue->tree();
			$review = $catalogue->review($tree,$action,$id);
		}
		$exclude = $review['scope'];
		foreach ($tree['list'] as $other=>$depth)
		{
			if ($depth < 0) { continue; }
			$row = $tree['rows'][$other];
			$template->assign_block_vars('dl_choices',array('ID'=>$other,'TITLE'=>access::plain($row['title']),'DEPTH'=>$depth,
				'PARENT'=>!isset($exclude[$other]),'FILE'=>$other !== $id && $row['allow_files'],
				'SELECT_PARENT'=>$form['parent'] === $other,'SELECT_FILE'=>$form['files_to'] === $other,'SELECT_CHILD'=>$form['children_to'] === $other));
		}
		add_form_key($key);
		$template->assign_vars(array('DL_FORM'=>true,'DL_ACTION'=>$action,'DL_LABEL'=>$language->lang('ACP_PLUS_DL_' . strtoupper($action)),
			'DL_ERROR'=>$error,'DL_STATE'=>$review['state'],'DL_INPUT'=>$form,'DL_PARENT_ROOT'=>$form['parent'] === 0,
			'DL_CHILD_ROOT'=>$form['children_to'] === 0,'DL_NAME'=>access::plain($tree['rows'][$id]['title'] ?? ''),
			'DL_AFFECTED'=>$language->lang('ACP_PLUS_DL_AFFECTED',$review['direct'],max(0,count($review['scope']) - 1),$review['below'],$review['grants']),
			'U_DL_SAVE'=>$this->link($action,$id),'U_BACK'=>$this->u_action));
	}
}
