<?php
namespace phpbb2plus\community\event;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class registration_reputation implements EventSubscriberInterface
{
	private $client;
	private $config;
	private $language;
	private $request;
	private $template;

	public function __construct($client, $config, $language, $request, $template)
	{
		$this->client = $client;
		$this->config = $config;
		$this->language = $language;
		$this->request = $request;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_register_data_before' => 'form', 'core.ucp_register_data_after' => array('validate', -100),
			'core.acp_board_config_edit_add' => 'settings');
	}

	public function form()
	{
		if (empty($this->config['plus_sfs_enable'])) { return; }
		$this->language->add_lang('registration_reputation', 'phpbb2plus/community');
		$this->template->assign_var('S_PLUS_SFS', true);
	}

	public function validate($event)
	{
		if (empty($this->config['plus_sfs_enable']) || !$event['submit'] || $event['error']) { return; }
		$data = $event['data'];
		$values = array('ip' => $this->request->server('REMOTE_ADDR', ''),
			'email' => htmlspecialchars_decode($data['email'], ENT_QUOTES),
			'username' => htmlspecialchars_decode($data['username'], ENT_QUOTES));
		if (!filter_var($values['ip'], FILTER_VALIDATE_IP)) { unset($values['ip']); }
		if (strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) { unset($values['email']); }
		if ($values['username'] === '' || strlen($values['username']) > 100) { unset($values['username']); }
		$deadline = microtime(true) + 4;
		foreach ($values as $type => $value)
		{
			$result = $this->lookup($type, $value, $deadline - microtime(true));
			if ($result === false) { continue; }
			if ($result === null)
			{
				// The request URL contains account information and must stay out of logs.
				error_log('Stop Forum Spam registration check unavailable.');
				if (empty($this->config['plus_sfs_fail_closed'])) { return; }
				$type = 'unavailable';
			}
			$this->language->add_lang('registration_reputation', 'phpbb2plus/community');
			$errors = $event['error'];
			$errors[] = $this->language->lang('PLUS_SFS_' . strtoupper($type));
			$event['error'] = $errors;
			return;
		}
	}

	private function lookup($type, $value, $seconds)
	{
		if ($seconds <= 0) { return null; }
		try
		{
			$response = $this->client->request('GET', 'https://api.stopforumspam.org/api', array(
				'query' => array($type => $value, 'f' => 'json'), 'headers' => array('Accept' => 'application/json'),
				'allow_redirects' => false, 'http_errors' => false, 'verify' => true, 'stream' => true,
				'timeout' => $seconds, 'connect_timeout' => min(2, $seconds), 'read_timeout' => $seconds));
			$body = $response->getBody();
			try
			{
				if ($response->getStatusCode() !== 200) { return null; }
				$text = Utils::copyToString($body, 65537);
			}
			finally { $body->close(); }
		}
		catch (GuzzleException | \RuntimeException $e) { return null; }
		if (strlen($text) > 65536) { return null; }
		$data = json_decode($text, true, 12);
		if (!is_array($data) || !in_array($data['success'] ?? null, array(1, '1', true), true)) { return null; }
		$appears = $data[$type]['appears'] ?? null;
		return in_array($appears, array(0, 1, '0', '1'), true) ? (bool) (int) $appears : null;
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'registration' || !isset($this->config['plus_sfs_enable'])) { return; }
		$this->language->add_lang('registration_reputation', 'phpbb2plus/community');
		$fields = array();
		foreach (array('plus_sfs_enable', 'plus_sfs_fail_closed') as $name)
		{
			$fields[$name] = array('lang' => strtoupper($name), 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => true);
		}
		$display = $event['display_vars'];
		$display['vars'] = phpbb_insert_config_array($display['vars'], $fields, array('after' => 'require_activation'));
		$event['display_vars'] = $display;
	}
}
