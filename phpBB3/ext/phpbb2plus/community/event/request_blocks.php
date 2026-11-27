<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;

class request_blocks implements EventSubscriberInterface
{
	private $blocks, $request, $config, $language;

	public function __construct($blocks, $request, $config, $language)
	{
		$this->blocks = $blocks; $this->request = $request; $this->config = $config; $this->language = $language;
	}

	public static function getSubscribedEvents() { return array('core.common' => 'check', 'core.permissions' => 'permission'); }

	public function permission($event)
	{
		$this->language->add_lang('info_acp_request_blocks', 'phpbb2plus/community');
		$this->language->add_lang('info_acp_program_files', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions['a_plus_ct_files'] = array('lang' => 'ACL_A_PLUS_CT_FILES', 'cat' => 'settings');
		$permissions['a_plus_ct_blocks'] = array('lang' => 'ACL_A_PLUS_CT_BLOCKS', 'cat' => 'settings');
		$event['permissions'] = $permissions;
	}

	private function server($name)
	{
		$value = $this->request->raw_variable($name, '', request_interface::SERVER);
		return is_string($value) ? $value : '';
	}

	public function check()
	{
		if (PHP_SAPI === 'cli' || defined('IN_INSTALL') || !isset($this->config['plus_ct_block_enabled'])) { return; }
		$ip = trim($this->server('REMOTE_ADDR'));
		if (!filter_var($ip, FILTER_VALIDATE_IP)) { $ip = '0.0.0.0'; }
		$agent = substr($this->server('HTTP_USER_AGENT'), 0, 2048);
		$host = substr($this->server('REMOTE_HOST'), 0, 255);
		if (!$this->blocks->matches($ip, $agent, $host)) { return; }
		$this->blocks->record($ip, $agent, $host, $this->server('SCRIPT_NAME'), $this->server('HTTP_REFERER'));
		$this->language->set_default_language($this->config['default_lang']);
		$this->language->add_lang('info_acp_request_blocks', 'phpbb2plus/community');
		$response = new Response($this->language->lang('PLUS_CT_BLOCKED'), 403,
			array('Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'));
		$response->send();
		exit;
	}
}
