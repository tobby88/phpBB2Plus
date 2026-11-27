<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\attachment\forbidden_extensions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class attachment_blocks implements EventSubscriberInterface
{
	private $rules;
	private $request;
	private $language;

	public function __construct($rules, $request, $language)
	{
		$this->rules = $rules; $this->request = $request; $this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.add_form_key'=>'native_form', 'core.adm_page_header'=>'release', 'core.garbage_collection'=>'release', 'core.permissions'=>'permissions');
	}

	public function permissions($event)
	{
		$this->language->add_lang('info_acp_attachment_blocks', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions[forbidden_extensions::PERMISSION] = array('lang'=>'ACL_A_PLUS_ATTACH_BLOCK', 'cat'=>'board');
		$event['permissions'] = $permissions;
	}

	public function release() { $this->rules->leave(); }

	public function native_form($event)
	{
		global $module;
		if ($event['form_name'] !== 'acp_attach' || !defined('IN_ADMIN') || !IN_ADMIN ||
			($module->p_name ?? '') !== 'acp_attachments' || $this->request->server('REQUEST_METHOD') !== 'POST') { return; }
		$mode = $module->p_mode;
		$submit = $this->request->is_set_post('submit');
		$add = $this->request->is_set_post('add_extension_check');
		if (!(($mode === 'extensions' && ($submit || $add)) || ($mode === 'ext_groups' && $submit))) { return; }
		if (!check_form_key('acp_attach')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
		try
		{
			$this->rules->enter();
			register_shutdown_function(array($this, 'release'));
			if ($mode === 'extensions' && $add)
			{
				$extension = $this->request->variable('add_extension', '');
				if ($extension !== '' && $this->rules->blocked($extension)) { throw new \DomainException('PLUS_ATTACH_BLOCK_REFUSED'); }
			}
			if (!$submit) { return; }
			$enabling = false;
			if ($mode === 'extensions')
			{
				$ids = $this->request->variable('extension_change_list', array(0));
				$groups = $this->request->variable('group_select', array(0));
				$selected = array();
				foreach ($ids as $i => $id) { $selected[$id] = (int) ($groups[$i] ?? 0); }
				foreach ($this->request->variable('extension_id_list', array(0)) as $deleted) { unset($selected[$deleted]); }
			}
			else
			{
				$action = $this->request->variable('action', '');
				if (!in_array($action, array('edit','add'), true)) { return; }
				$group = $action === 'add' ? -1 : $this->request->variable('g', 0);
				$selected = array_fill_keys($this->request->variable('extensions', array(0)), $group);
				$enabling = $group > 0 && $this->rules->enabling_group($group, $this->request->is_set_post('allow_group'), $this->request->is_set_post('allow_in_pm'));
			}
			$changed = array();
			foreach ($this->rules->native_extensions(array_keys($selected)) as $row)
			{
				$target = $selected[$row['extension_id']] ?? 0;
				if ($target && ($enabling || (int) $row['group_id'] !== $target)) { $changed[] = $row['extension']; }
			}
			if ($this->rules->blocked_types($changed)) { throw new \DomainException('PLUS_ATTACH_BLOCK_REFUSED'); }
		}
		catch (\DomainException $e)
		{
			$this->release();
			$this->language->add_lang('info_acp_attachment_blocks', 'phpbb2plus/community');
			trigger_error($this->language->lang($e->getMessage()) . adm_back_link($module->module->u_action), E_USER_WARNING);
		}
	}
}
