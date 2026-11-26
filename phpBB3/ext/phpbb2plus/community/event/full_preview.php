<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class full_preview implements EventSubscriberInterface
{
	private $request;
	private $template;
	private $language;
	private $preferences;
	private $kind = '';
	private $fragment = false;

	public function __construct($request, $template, $language, $preferences)
	{
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->preferences = $preferences;
	}

	public static function getSubscribedEvents()
	{
		return array('core.modify_posting_parameters' => 'post_request', 'core.ucp_pm_compose_modify_data' => 'pm_request',
			'core.posting_modify_template_vars' => 'post_editor', 'core.ucp_pm_compose_template' => 'pm_editor',
			'core.page_footer_after' => 'result');
	}

	public function post_request($event) { $this->request_preview($event, 'post', $event['mode']); }
	public function pm_request($event) { $this->request_preview($event, 'pm', $event['action']); }

	private function request_preview($event, $kind, $mode)
	{
		if (!$this->request->is_set_post('plus_full_preview_request')) { return; }
		$modes = $kind === 'pm' ? array('post', 'reply', 'quote', 'quotepost', 'edit', 'forward') : array('post', 'reply', 'quote', 'edit');
		// Both native controllers may upload files during an ordinary preview. The partial
		// response accepts text and already issued attachment references only.
		$commands = array('post', 'save', 'load', 'cancel', 'confirm', 'delete', 'add_file', 'delete_file',
			'add_to', 'add_bcc', 'remove_u', 'remove_g', 'plus_inline_expand');
		if (!$event['preview'] || $event['submit'] || !in_array($mode, $modes, true) ||
			array_intersect($commands, $this->request->variable_names()) || $this->request->variable_names(request_interface::FILES))
		{
			trigger_error('FORM_INVALID');
		}
		$this->kind = $kind;
	}

	public function post_editor() { $this->offer('post'); }
	public function pm_editor() { $this->offer('pm'); }

	private function offer($kind)
	{
		if (!$this->preferences->enabled('plus_full_preview')) { return; }
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$this->template->assign_var('PLUS_FULL_PREVIEW_KIND', $kind);
		// This event is reached after the normal forum/PM permissions and form validation.
		$this->fragment = $kind === $this->kind;
	}

	public function result()
	{
		if (!$this->fragment) { return; }
		header('Cache-Control: private, no-store');
		header('X-phpBB-Plus-Preview: ' . $this->kind);
		$this->template->set_filenames(array('body' => '@phpbb2plus_community/full_preview_result.html'));
	}
}
