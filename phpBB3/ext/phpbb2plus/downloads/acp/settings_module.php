<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\settings;

class settings_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_settings';
	public $page_title = 'ACP_PLUS_DL_SETTINGS';

	public function main($id,$mode)
	{
		global $phpbb_container;
		$lang = $phpbb_container->get('language');
		$lang->add_lang(array('downloads','info_acp_downloads'),'phpbb2plus/downloads');
		$store = $phpbb_container->get('phpbb2plus.downloads.settings');
		$request = $phpbb_container->get('request');
		$template = $phpbb_container->get('template');
		$error = '';
		$stale = false;
		try
		{
			$view = $store->review();
			$values = $view['values'];
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				if (!check_form_key('plus_dl_settings') || !$request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				$input = $request->raw_variable('v',array(),request_interface::POST);
				try
				{
					$store->save($request->variable('state','',false,request_interface::POST),$input);
					redirect($this->u_action . '&amp;saved=1');
				}
				catch (\DomainException $e)
				{
					if (in_array($e->getMessage(),array('NO_AUTH_OPERATION','FORM_INVALID'),true)) { throw $e; }
					$error = $lang->lang($e->getMessage());
					$stale = $e->getMessage() === 'ACP_PLUS_DL_SETTINGS_CHANGED';
					foreach ($values as $name=>$value) { if (isset($input[$name]) && is_string($input[$name])) { $values[$name] = $input[$name]; } }
				}
			}
			foreach (settings::SECTIONS as $section=>$keys)
			{
				$template->assign_block_vars('sections',array('TITLE'=>$lang->lang('ACP_PLUS_DL_CFG_GROUP_' . strtoupper($section))));
				foreach ($keys as $name)
				{
					$options = settings::options($name);
					$help = 'ACP_PLUS_DL_CFG_' . strtoupper($name) . '_HELP';
					$template->assign_block_vars('sections.fields',array('NAME'=>$name,'TITLE'=>$lang->lang('ACP_PLUS_DL_CFG_' . strtoupper($name)),
						'HELP'=>$lang->is_set($help) ? $lang->lang($help) : '','VALUE'=>$values[$name],'SELECT'=>(bool) $options,'NUMERIC'=>isset(settings::RANGES[$name])));
					if ($options && !array_key_exists($values[$name],$options))
					{ $template->assign_block_vars('sections.fields.choices',array('VALUE'=>$values[$name],'NAME'=>$lang->lang('ACP_PLUS_DL_CFG_STORED',$values[$name]),'SELECTED'=>true)); }
					foreach ($options as $key=>$label)
					{ $template->assign_block_vars('sections.fields.choices',array('VALUE'=>$key,'NAME'=>$lang->lang($label),'SELECTED'=>(string) $key === $values[$name])); }
				}
			}
			foreach (settings::ARCHIVED as $name)
			{
				if (isset($view['archived'][$name]))
				{ $template->assign_block_vars('archived',array('NAME'=>$lang->lang('ACP_PLUS_DL_CFG_' . strtoupper($name)),'VALUE'=>$view['archived'][$name])); }
			}
			$template->assign_vars(array('U_DL_SAVE'=>$this->u_action,'DL_STATE'=>$view['state'],'DL_ERROR'=>$error,'DL_STALE'=>$stale,
				'DL_SAVED'=>$request->server('REQUEST_METHOD') === 'GET' && $request->is_set('saved',request_interface::GET)));
			add_form_key('plus_dl_settings');
		}
		catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}
}
