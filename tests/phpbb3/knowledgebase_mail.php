<?php
// Exercise the native messenger with an in-memory transport on the disposable board only.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
$messages = array();
$phpbb_dispatcher->addListener('core.notification_message_email', function ($event) use (&$messages)
{
	$messages[] = array($event['addresses']['to'][0]['email'], $event['subject'], $event['msg']);
	$event['break'] = true;
});
$task = $phpbb_container->get('phpbb2plus.knowledgebase.notices');
$publication = $phpbb_container->get('phpbb2plus.knowledgebase.publication');
$articles = $phpbb_container->get('phpbb2plus.knowledgebase.articles');
$table = $table_prefix . 'plus_kb_';
$db->sql_query("UPDATE {$table}settings SET setting_value='2' WHERE setting_name='notify'");
$articles->refresh();
$db->sql_query("UPDATE {$table}articles SET approval=0,revision=3 WHERE article_id=202");
foreach (array(201,202) as $id) { $publication->pending(array('article_id' => $id, 'revision' => 1)); }
$publication->pending(array('article_id' => 202, 'revision' => 3));
$config->set('email_enable', 0);
$task->run();
$result = $db->sql_query("SELECT article_id,revision,lease_until FROM {$table}mail");
$rest = $db->sql_fetchrowset($result);
$db->sql_freeresult($result);
if ($messages || count($rest) !== 1 || (int) $rest[0]['article_id'] !== 202 || (int) $rest[0]['revision'] !== 3 || (int) $rest[0]['lease_until'] <= time())
{ throw new RuntimeException('A stale review mail survived or mail escaped the disabled transport'); }
$config->set('email_enable', 1);
$config->set('email_package_size', 0);
$config->set('board_email', 'review@example.invalid');
foreach (array('de' => 'Neuer Artikel', 'en' => 'Article') as $lang => $fragment)
{
	$config->set('default_lang', $lang);
	if ($lang === 'en') { $publication->pending(array('article_id' => 202, 'revision' => 3)); }
	$db->sql_query("UPDATE {$table}mail SET lease_until=0");
	$task->run();
	$task->run();
	if (count($messages) !== 1 || $messages[0][0] !== 'review@example.invalid' || stripos($messages[0][1], $fragment) === false ||
		strpos($messages[0][2], 'Ungeprüfte Anleitung') === false || strpos($messages[0][2], '/knowledge/article/202') === false ||
		strpos($messages[0][2], 'sid=') !== false) { throw new RuntimeException('Incorrect recipient, localised message, article link or duplicate mail'); }
	$messages = array();
}
$config->set('default_lang', 'de');
$config->set('email_enable', 0);
$db->sql_query("UPDATE {$table}settings SET setting_value='1' WHERE setting_name='notify'");
