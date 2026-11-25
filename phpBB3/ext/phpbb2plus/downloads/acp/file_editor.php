<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\editor_form;
use phpbb2plus\downloads\service\file_display;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class file_editor
{
	public function open($container,$back,$action,$posted)
	{
		global $module,$phpbb_admin_path,$phpEx;
		$access = $container->get('phpbb2plus.downloads.access');
		$store = $container->get('phpbb2plus.downloads.submissions');
		$request = $container->get('request');
		$template = $container->get('template');
		$lang = $container->get('language');
		$lang->add_lang('downloads','phpbb2plus/downloads');
		$id = $request->variable('file',0,false,request_interface::GET);
		if (($action === 'edit') !== ($id > 0)) { throw new \DomainException('FORM_INVALID'); }
		try { $item = $id ? $access->editor_file($id) : null; }
		catch (NotFoundHttpException $e) { throw new \DomainException('ACP_PLUS_DL_FILE_CHANGED'); }
		$raw = $item ? json_decode($item['source_json'],true) : array();
		$can_approve = $access->file_admin('approve');
		$csrf = 'plus_dl_acp_file_' . $id;
		$url = $back . '&amp;action=' . $action . ($id ? '&amp;file=' . $id : '');
		$form = editor_form::metadata($request,$item,'',$posted);
		$existing = $id ? $store->saved_fields($id) : array();
		$fields = $posted ? $request->raw_variable('field',array(),request_interface::POST) : $existing;
		$category = $posted ? $request->variable('category',0,false,request_interface::POST) : (int) ($item['cat_id'] ?? 0);
		$revision = $posted ? $request->variable('revision',-1,false,request_interface::POST) : (int) ($item['review_revision'] ?? 0);
		$nonce = $posted ? $request->variable('nonce','',false,request_interface::POST) : bin2hex(random_bytes(16));
		$count = (string) ($item['downloads'] ?? '0');
		$set_count = !$id || ($posted && $request->is_set_post('set_count'));
		$admin = array('pinned'=>$posted ? $request->variable('pinned','',false,request_interface::POST) : (string) ($item['pinned'] ?? '0'),
			'icon'=>$posted ? $request->variable('icon','',true,request_interface::POST) : ($item['icon_name'] ?? ''),
			'preview_link'=>$posted ? $request->variable('preview_link','',false,request_interface::POST) : (string) ($item['preview_link'] ?? '0'),
			'approved'=>$posted ? ($request->is_set_post('approved') ? $request->variable('approved','',false,request_interface::POST) : null) :
				($can_approve ? (string) ($item['approved'] ?? '1') : null),
			'downloads'=>$set_count ? ($posted ? $request->variable('downloads','',false,request_interface::POST) : $count) : null,
			'previous_downloads'=>$posted ? $request->variable('previous_downloads','',false,request_interface::POST) : $count);
		$remove = $request->is_set_post('remove_preview');
		$error = '';
		$stale = false;
		if ($posted)
		{
			if (!check_form_key($csrf) || !$request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
			try
			{
				$file = $request->raw_variable('original',array(),request_interface::FILES);
				$preview = $request->raw_variable('screenshot',array(),request_interface::FILES);
				if ($id) { $store->edit($id,$category,$revision,$form,$fields,$file,$preview,$remove,$admin); }
				else { $id = $store->submit($category,$nonce,$form,$fields,$file,$preview,$admin); }
				redirect($back . '&amp;action=edit&amp;file=' . $id . '&amp;saved=1');
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'NO_AUTH_OPERATION') { throw $e; }
				$error = $lang->lang($e->getMessage());
				$stale = $e->getCode() === 409 && $e->getMessage() !== 'PLUS_DL_FIELDS_CHANGED';
			}
			catch (NotFoundHttpException $e) { throw new \DomainException('ACP_PLUS_DL_FILE_CHANGED'); }
		}
		foreach ($access->categories() as $cat=>$row)
		{
			if ($access->file_destination($cat))
			{ $template->assign_block_vars('dl_destinations',array('ID'=>$cat,'TITLE'=>access::plain($row['title']),'SELECTED'=>$cat === $category)); }
		}
		$icons = file_display::icons();
		$icon = $item['icon_name'] ?? '';
		if ($icon !== '' && !isset($icons[$icon])) { $icons[$icon] = $icon; }
		$root = $container->get('path_helper')->get_web_root_path();
		foreach ($icons as $name)
		{ $template->assign_block_vars('dl_icons',array('NAME'=>$name,'SELECTED'=>$name === $admin['icon'],'URL'=>file_display::icon_url($name,$root))); }
		editor_form::display($template,$lang,$store,$item,$form,$fields,$existing);
		$mirrors = '\phpbb2plus\downloads\acp\mirrors_module';
		$mirror_url = $item && $access->mirror_admin() && $module->is_active($mirrors,'manage') ?
			append_sid($phpbb_admin_path . 'index.' . $phpEx,'i=' . str_replace('\\','-',$mirrors) . '&amp;mode=manage&amp;action=file&amp;file=' . $id) : '';
		$template->assign_vars(array('DL_EDITING'=>(bool) $item,'DL_CURRENT_SOURCE'=>$item && $item['asset_status'] === 'remote' ? ($raw['file_dlurl'] ?? '') : ($raw['real_name'] ?? ''),
			'DL_CURRENT_PREVIEW'=>$raw['file_ssurl'] ?? '','DL_HAS_PREVIEW'=>$item && (!empty($item['preview_name']) || !empty($raw['file_ssurl'])),
			'DL_EMPTY_DESCRIPTION'=>$item && ($raw['file_longdesc'] ?? '') === '','DL_REMOVE_PREVIEW'=>$remove,
			'DL_ICON_NAME'=>$admin['icon'],'DL_PINNED'=>$admin['pinned'] === '1','DL_LINK'=>$admin['preview_link'] === '1','DL_CAN_APPROVE'=>$can_approve,
			'DL_APPROVED'=>$can_approve ? $admin['approved'] === '1' : !empty($item['approved']),
			'DL_COUNTER'=>$admin['downloads'] ?? $count,'DL_SET_COUNT'=>$set_count,'DL_PREVIOUS_COUNT'=>$admin['previous_downloads'],
			'DL_REVISION'=>$revision,'DL_NONCE'=>$nonce,'DL_ERROR'=>$error,'DL_STALE'=>$stale,
			'DL_SAVED'=>!$posted && $request->is_set('saved',request_interface::GET),'U_DL_SAVE'=>$url,'U_DL_MIRRORS'=>$mirror_url,'U_BACK'=>$back));
		add_form_key($csrf);
	}
}
