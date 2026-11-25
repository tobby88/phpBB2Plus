<?php
namespace phpbb2plus\downloads\service;

use phpbb\request\request_interface;

class editor_form
{
	public static function metadata($request,$item,$username,$posted)
	{
		$form = array();
		foreach (array('title','summary','description','version','author','documentation','url','preview_url') as $key)
		{ $form[$key] = htmlspecialchars_decode($request->variable($key,'',true,request_interface::POST),ENT_QUOTES); }
		$form['license'] = $request->variable('license',0,false,request_interface::POST);
		$form['fields_revision'] = $request->variable('fields_revision','',false,request_interface::POST);
		if (!$item && !$request->is_set_post('author')) { $form['author'] = $username; }
		if ($item && !$posted)
		{
			$raw = json_decode($item['source_json'],true) ?: array();
			$form['title'] = access::plain($item['title']);
			$form['summary'] = access::plain($item['summary']);
			$form['description'] = access::plain($raw['file_longdesc'] ?? '');
			$form['author'] = access::plain($raw['file_creator'] ?? '');
			$form['version'] = access::plain($raw['file_version'] ?? '');
			$form['documentation'] = (string) ($raw['file_docsurl'] ?? '');
			$form['license'] = (int) $item['license_id'];
		}
		return $form;
	}

	public static function display($template,$language,$submissions,$item,$form,$fields,$existing)
	{
		$licenses = $submissions->licenses();
		if ($item && $item['license_id'] && !isset($licenses[$item['license_id']]))
		{ $licenses[(int) $item['license_id']] = $language->lang('PLUS_DL_MISSING_LICENSE',(int) $item['license_id']); }
		foreach ($licenses as $id=>$title)
		{ $template->assign_block_vars('dl_licenses',array('ID'=>$id,'TITLE'=>$title,'SELECTED'=>$form['license'] === $id)); }
		$definitions = $submissions->fields();
		foreach ($definitions as $id=>$definition)
		{
			if ($definition['type'] < 0 || $definition['type'] > 5) { continue; }
			$value = $fields[$id] ?? '';
			$options = array_unique(array_merge($definition['options'],$existing[$id] ?? array()));
			$template->assign_block_vars('dl_custom',array('ID'=>$id,'TYPE'=>$definition['type'],'TITLE'=>$definition['title'],
				'SUMMARY'=>$definition['description'],'VALUE'=>is_string($value) ? $value : ($existing[$id][0] ?? '')));
			foreach ($options as $option)
			{ $template->assign_block_vars('dl_custom.choices',array('VALUE'=>$option,'SELECTED'=>in_array($option,(array) $value,true))); }
		}
		foreach ($form as $key=>$value) { $template->assign_var('DL_' . strtoupper($key),$value); }
		$template->assign_var('DL_FIELDS_REVISION',field_data::revision($definitions));
	}
}
