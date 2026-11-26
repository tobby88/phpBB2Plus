<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class quick_reply implements EventSubscriberInterface
{
	private $config, $auth, $language, $root, $ext;

	public function __construct($config, $auth, $language, $root, $ext)
	{
		$this->config = $config;
		$this->auth = $auth;
		$this->language = $language;
		$this->root = $root;
		$this->ext = $ext;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_quick_reply_template_vars' => 'options');
	}

	public function options($event)
	{
		$this->language->add_lang('posting');
		$this->language->add_lang('quick_reply', 'phpbb2plus/community');
		$vars = $event['tpl_ary'];
		$forum = (int) $event['topic_data']['forum_id'];
		$hidden = $vars['QR_HIDDEN_FIELDS'];
		$vars['S_PLUS_QR_QUOTE'] = strpos($hidden, build_hidden_fields(array('disable_bbcode' => 1))) === false;
		if (strpos($hidden, build_hidden_fields(array('disable_smilies' => 1))) === false)
		{
			$vars['U_PLUS_QR_SMILIES'] = append_sid($this->root . 'posting.' . $this->ext, 'mode=smilies&amp;f=' . $forum);
		}
		$choices = array(
			'attach_sig' => $this->config['allow_sig'] && $this->auth->acl_get('f_sigs', $forum) && $this->auth->acl_get('u_sig'),
			'notify' => $this->config['allow_topic_notify'] && $this->config['email_enable'],
		);
		foreach ($choices as $field => $allowed)
		{
			if (!$allowed) { continue; }
			$vars['S_PLUS_QR_' . strtoupper($field)] = true;
			// Turn the native default into an editable checkbox without sending two values.
			$default = build_hidden_fields(array($field => 1));
			$vars['S_PLUS_QR_' . strtoupper($field) . '_CHECKED'] = strpos($hidden, $default) !== false;
			$hidden = str_replace($default, '', $hidden);
		}
		$vars['QR_HIDDEN_FIELDS'] = $hidden;
		$event['tpl_ary'] = $vars;
	}
}
