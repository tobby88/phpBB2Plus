<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\game_editor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class games_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_arc_game_editor';
	public $page_title = 'ACP_PLUS_ARC_GAMES';

	private function edit($service, $game_id, $lang, $request, $template, $base)
	{
		$game = $service->find($game_id);
		$form = 'plus_arc_edit_game_' . $game_id;
		$input = array();
		foreach (game_editor::TEXT as $name) { $input[$name] = catalog::plain($game[$name]); }
		foreach (game_editor::LIMITS + game_editor::CHOICES as $name=>$unused) { $input[$name] = game_editor::display_number($game[$name]); }
		$input += array('cat_id'=>'keep','group_required'=>'keep','rank_required'=>'keep');
		$revision = game_editor::revision($game);
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($form)) { send_status_line(403,'Forbidden'); trigger_error('FORM_INVALID',E_USER_WARNING); }
			foreach ($input as $key=>$unused)
			{
				if (!$request->is_set_post($key)) { throw new \DomainException('FORM_INVALID'); }
				// The game instructions can contain significant leading/trailing
				// whitespace and literal entities. Validate at the storage boundary.
				$input[$key] = in_array($key,game_editor::TEXT,true) ? $request->raw_variable($key,'',request_interface::POST) :
					$request->variable($key,'',false,request_interface::POST);
			}
			$revision = $request->variable('revision','',false,request_interface::POST);
			try
			{
				$service->save($game_id,$revision,$input);
				redirect($base . '&amp;game=' . $game_id . '&amp;saved=1');
			}
			catch (\DomainException $e) { $template->assign_var('ARC_GAME_ERROR',$lang->lang($e->getMessage())); }
		}
		elseif ($request->variable('saved',0,false,request_interface::GET)) { $template->assign_var('ARC_GAME_SAVED',true); }
		list($scopes,$current) = $service->options($game);
		$options = array();
		foreach (game_editor::CHOICES as $key=>$last)
		{
			for ($value=0; $value<=$last; ++$value)
			{
				$label = $last === 1 ? ($value ? 'YES' : 'NO') : 'PLUS_ARC_EDIT_' . strtoupper($key) . '_' . $value;
				$options[$key][$value] = $lang->lang($label);
			}
			$stored = game_editor::display_number($game[$key]);
			if (!isset($options[$key][$stored])) { $options[$key][$stored] = $lang->lang('PLUS_ARC_EDIT_RETAIN',$stored); }
		}
		foreach ($scopes as $key=>$choices)
		{
			$old = $key==='group_required' && (int) $game['native_group_id']>0 ? $game['native_group_id'] : $game[$key];
			$label = $current[$key] ?: ($key==='cat_id' && (int) $old<=0 ? $lang->lang('PLUS_ARC_EDIT_UNCATEGORISED') :
				((int) $old===0 ? $lang->lang('ALL') : '#' . (int) $old));
			$options[$key] = array('keep'=>$lang->lang('PLUS_ARC_EDIT_RETAIN',$label));
			if ($key==='cat_id') { $options[$key][-1] = $lang->lang('PLUS_ARC_EDIT_UNCATEGORISED'); }
			else { $options[$key][0] = $lang->lang('ALL'); }
			$options[$key] += $choices;
		}
		foreach ($options as $field=>$choices)
		{
			$template->assign_block_vars('choices',array('NAME'=>$field,'LABEL'=>$lang->lang('PLUS_ARC_EDIT_' . strtoupper($field))));
			foreach ($choices as $value=>$label) { $template->assign_block_vars('choices.option',array('VALUE'=>$value,'TEXT'=>$label,'CURRENT'=>(string) $value===$input[$field])); }
		}
		foreach (game_editor::LIMITS as $field=>$max)
		{
			$template->assign_block_vars('numbers',array('NAME'=>$field,'LABEL'=>$lang->lang('PLUS_ARC_EDIT_' . strtoupper($field)),
				'VALUE'=>$input[$field],'DIGITS'=>ctype_digit(game_editor::display_number($game[$field]))));
		}
		add_form_key($form);
		$template->assign_vars(array('S_ARC_GAME'=>true,'ARC_GAME_ID'=>$game_id,'ARC_GAME_KEY'=>$game['game_name'],'ARC_GAME_TITLE'=>$input['game_desc'],
			'ARC_GAME_TEXT'=>$input['instructions'],'ARC_GAME_REVISION'=>$revision,'U_ARC_GAME_EDIT'=>$base . '&amp;game=' . $game_id,
			'U_ARC_GAME_RELOAD'=>$base . '&amp;game=' . $game_id));
	}

	public function main($id, $mode)
	{
		global $phpbb_container,$request,$template,$user;
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_arc_games','phpbb2plus/arcade');
		$lang->add_lang('arcade','phpbb2plus/arcade');
		$service = $phpbb_container->get('phpbb2plus.arcade.game_editor');
		$term = utf8_substr(htmlspecialchars_decode($request->variable('search','',true,request_interface::GET),ENT_QUOTES),0,255);
		$list = $this->u_action . ($term==='' ? '' : '&amp;search=' . rawurlencode($term));
		$sort = $request->variable('sort_mode','game_id',false,request_interface::GET);
		if (!isset(game_editor::SORT[$sort])) { $sort = 'game_id'; }
		$order = $request->variable('order','DESC',false,request_interface::GET)==='ASC' ? 'ASC' : 'DESC';
		$category = max(-1,$request->variable('cat',0,false,request_interface::GET));
		if ($sort!=='game_id') { $list .= '&amp;sort_mode=' . $sort; }
		if ($order==='ASC') { $list .= '&amp;order=ASC'; }
		if ($category) { $list .= '&amp;cat=' . $category; }
		$offset = max(0,$request->variable('start',0,false,request_interface::GET));
		$base = $list . ($offset ? '&amp;start=' . $offset : '');
		$template->assign_var('U_ARC_GAME_LIST',$base);
		try
		{
			$game = $request->variable('game',0,false,request_interface::GET);
			if ($game)
			{
				$this->edit($service,$game,$lang,$request,$template,$base);
				return;
			}
			list($rows,$total,$size,$start) = $service->listing($offset,$term,$category,$sort,$order);
		}
		catch (AccessDeniedHttpException $e) { send_status_line(403,'Forbidden'); trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()),E_USER_WARNING); }
		foreach ($rows as $row)
		{
			$template->assign_block_vars('games',array('ID'=>(int) $row['game_id'],'KEY'=>$row['game_name'],'TITLE'=>catalog::plain($row['game_desc']),
				'CATEGORY'=>catalog::plain($row['cat_name']),'ACTIVE'=>(bool) $row['game_avail'],'URL'=>$base . '&amp;game=' . (int) $row['game_id']));
		}
		$pagination = $phpbb_container->get('pagination');
		$labels = array('game_id'=>'PLUS_ARC_SORT_DEFAULT','offline'=>'PLUS_ARC_EDIT_GAME_AVAIL','cat_id'=>'PLUS_ARC_EDIT_CAT_ID');
		foreach (game_editor::SORT as $value=>$unused)
		{
			$template->assign_block_vars('sorts',array('VALUE'=>$value,'TEXT'=>$lang->lang($labels[$value] ?? 'PLUS_ARC_SORT_' . strtoupper($value)),'CURRENT'=>$value===$sort));
		}
		foreach (array(0=>$lang->lang('ALL'),-1=>$lang->lang('PLUS_ARC_EDIT_UNCATEGORISED'))+$service->category_options() as $value=>$label)
		{
			$template->assign_block_vars('categories',array('VALUE'=>$value,'TEXT'=>$label,'CURRENT'=>$value===$category));
		}
		$pagination->generate_template_pagination($list,'pagination','start',$total,$size,$start);
		$template->assign_vars(array('U_ARC_GAME_SEARCH'=>$this->u_action,'ARC_GAME_SEARCH'=>$term,'ARC_GAME_ASC'=>$order==='ASC',
			'ARC_GAME_HIDDEN'=>build_hidden_fields(array('i'=>'-phpbb2plus-arcade-acp-games_module','mode'=>'manage','sid'=>$user->session_id)),
			'PAGE_NUMBER'=>$pagination->on_page($total,$size,$start)));
	}
}
