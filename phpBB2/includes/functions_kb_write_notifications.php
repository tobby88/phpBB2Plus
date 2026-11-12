<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';

// The author transaction is already committed. Read current state through an
// independent, read-only owner; never adopt a caller snapshot or require the
// author's old permissions to send a legitimate pending-article notice.
class PhpbbKbNotificationDatabase extends PhpbbKbPublicationDatabase
{
	function begin()
	{
		if ($this->transactional || $this->finished) { kb_publication_fail(); }
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION READ ONLY'); $this->transactional = true;
		kb_publication_pin_tables($this, array(KB_ARTICLES_TABLE, KB_CONFIG_TABLE, CONFIG_TABLE));
	}
	function sql_query($sql, $transaction = false)
	{
		if ($transaction !== false || !is_string($sql) || !preg_match('/^\s*SELECT\b/i', $sql)) { kb_publication_fail(); }
		return $this->control($sql);
	}
}

function kb_notification_ready($database, $article_id)
{
	$article_id = kb_counter_category_id($article_id);
	$owner = new PhpbbKbNotificationDatabase($database, 'moderator');
	try {
		$owner->begin();
		$articles = $owner->rows('SELECT article_id,article_title,approved FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $article_id . ' AND approved IN (0,2) LOCK IN SHARE MODE');
		if (count($articles) !== 1) { return false; }
		$notify = $owner->rows("SELECT config_value FROM " . KB_CONFIG_TABLE . " WHERE config_name='notify' LOCK IN SHARE MODE");
		if (count($notify) !== 1 || !in_array($notify[0]['config_value'], array('0','1','2'), true)) { kb_publication_fail(); }
		if ($notify[0]['config_value'] !== '2') { return false; }
		$settings = array();
		foreach ($owner->rows("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('board_email','default_lang','sitename','board_email_sig') LOCK IN SHARE MODE") as $row) { $settings[$row['config_name']] = $row['config_value']; }
		if (count($settings) !== 4) { kb_publication_fail(); }
		return array('article'=>$articles[0], 'settings'=>$settings);
	} finally { $owner->release(); }
}

// Called only after confirmed article commit and release of its SQL owner.
// A failed transport cannot undo or misreport the saved article.
function kb_notify_after_write($outcome)
{
	global $phpbb_root_path, $phpEx, $board_config, $db;
	if (empty($outcome['notify_email'])) { return true; }
	$original_config = $board_config;
	try {
		$ready = kb_notification_ready($db, $outcome['article_id']);
		if ($ready === false) { return true; }
		$board_config = array_merge($board_config, $ready['settings']);
		if (!filter_var($board_config['board_email'], FILTER_VALIDATE_EMAIL)) { return false; }
		require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
		$emailer = new emailer($board_config['smtp_delivery'], true);
		$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
		$emailer->email_address($board_config['board_email']);
		$emailer->use_template('kb_pending_article', $board_config['default_lang']);
		$emailer->assign_vars(array('SITENAME'=>$board_config['sitename'], 'ARTICLE_TITLE'=>$ready['article']['article_title'],
			'U_REVIEW'=>phpbb_board_url('admin/admin_kb_art.' . $phpEx),
			'EMAIL_SIG'=>str_replace('<br />', "\n", $board_config['board_email_sig'])));
		return (bool)$emailer->send();
	} catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
	finally { $board_config = $original_config; }
}
