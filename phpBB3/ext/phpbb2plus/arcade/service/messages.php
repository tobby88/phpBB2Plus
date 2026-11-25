<?php
namespace phpbb2plus\arcade\service;

class messages
{
	private $language;
	private $request;
	private $root;
	private $php_ext;

	public function __construct($language, $request, $root, $php_ext)
	{
		$this->language = $language;
		$this->request = $request;
		$this->root = $root;
		$this->php_ext = $php_ext;
	}

	public function send($sender, $username, $ip, $recipient, $lang, $path, $subject_key, $body_key, array $args = array())
	{
		include_once $this->root . 'includes/functions_privmsgs.' . $this->php_ext;
		$locale = clone $this->language;
		$locale->set_user_language($lang, true);
		$locale->add_lang('arcade', 'phpbb2plus/arcade');
		$subject = utf8_htmlspecialchars($locale->lang($subject_key));
		$url = rtrim(generate_board_url(), '/') . '/app.' . $this->php_ext . '/arcade/' . $path;
		$text = utf8_htmlspecialchars($locale->lang($body_key, ...$args) . "\n\n" . $url);
		$uid = $bits = ''; $flags = 0;
		generate_text_for_storage($text, $uid, $bits, $flags, false, true, false);
		$data = array('from_user_id'=>(int) $sender, 'from_user_ip'=>$ip, 'from_username'=>$username,
			'reply_from_root_level'=>0, 'reply_from_msg_id'=>0, 'icon_id'=>0, 'enable_sig'=>false, 'enable_bbcode'=>false,
			'enable_smilies'=>false, 'enable_urls'=>true, 'bbcode_bitfield'=>$bits, 'bbcode_uid'=>$uid, 'message'=>$text,
			'attachment_data'=>array(), 'filename_data'=>array(), 'address_list'=>array('u'=>array((int) $recipient=>'to')));
		// The native composer consumes its loaded draft. Generated notices have no draft.
		$draft = $this->request->raw_variable('draft_loaded', null);
		$this->request->overwrite('draft_loaded', null);
		try { return submit_pm('post', $subject, $data, false); }
		finally { $this->request->overwrite('draft_loaded', $draft); }
	}
}
