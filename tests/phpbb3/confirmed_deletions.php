<?php
// Exercise real native storage on the disposable converted forum.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
function deleted_test_rows($sql)
{
	global $db;
	$r = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
}
function deleted_test_expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function deleted_test_marker($id, $status)
{
	$row = deleted_test_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=' . (int) $id)[0];
	deleted_test_expect((int) $row['plus_legacy_erased'] === (int) $status && (int) $row['post_visibility'] === ($status ? ITEM_DELETED : ITEM_APPROVED) &&
		($status ? ($row['post_text'] === '' && $row['post_subject'] === '' && !$row['post_username'] && (int) $row['poster_id'] === ANONYMOUS &&
			!$row['post_edit_user'] && (int) $row['plus_public_post'] === 1) : strpos($row['post_text'], 'Contents ' . $id) !== false),
		'Unexpected body, author, publication or deletion state for ' . $id);
}
function deleted_test_topic($id)
{
	return deleted_test_rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $id)[0];
}
function deleted_test_files($dir, $shared, $solo)
{
	foreach (array('plus_deleted_shared.gif' => $shared, 'plus_deleted_solo.gif' => $solo, 'thumb_plus_deleted_solo.gif' => $solo) as $file => $want)
	{
		clearstatcache(true, $dir . $file);
		deleted_test_expect(is_file($dir . $file) === $want, 'Unexpected file state for ' . $file);
	}
}
$dir = $phpbb_root_path . $config['upload_path'] . '/';
$queue = $table_prefix . 'plus_erased_files';
$quota = $phpbb_container->get('phpbb2plus.community.attachment_quotas');
$member = (int) deleted_test_rows("SELECT user_id FROM " . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$service = $phpbb_container->get('phpbb2plus.community.confirmed_deletions');
$native = $phpbb_container->get('content.visibility');
switch ($argv[2])
{
	case 'upgrade':
		$tools = $phpbb_container->get('dbal.tools');
		$class = \phpbb2plus\community\migrations\v134_erased_files::class;
		$migrations = $table_prefix . 'migrations';
		$name = deleted_test_rows("SELECT migration_name FROM $migrations WHERE " . $db->sql_in_set('migration_name',array($class,'\\'.$class)))[0]['migration_name'];
		deleted_test_expect(!deleted_test_rows("SELECT attach_id FROM $queue"), 'Upgrade fixture requires an unused native cleanup queue');
		$tools->sql_table_drop($queue);
		$config->delete('plus_erased_files_due');
		$db->sql_query("DELETE FROM $migrations WHERE migration_name='" . $db->sql_escape($name) . "'");
		$migrator = $phpbb_container->get('migrator');
		$migrator->load_migration_state();
		$migrator->set_migrations(array($name));
		for ($step = 0; !$migrator->finished() && $step < 8; ++$step) { $migrator->update(); }
		deleted_test_expect($migrator->finished() && $tools->sql_table_exists($queue) && isset($config['plus_erased_files_due']) &&
			!$phpbb_container->get('phpbb2plus.community.erased_files_cron')->should_run(), 'An existing installation failed to gain an idle cleanup queue');
		break;
	case 'seed':
		$config->set('plus_test_erased_files',(int) $config['num_files'],false);
		$config->set('plus_test_erased_bytes',(int) $config['upload_dir_size'],false);
		$config->set('plus_test_erased_quota',(int) $quota->occupied($member,false),false);
		$topic = deleted_test_topic(1);
		$post = deleted_test_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1')[0];
		$counts = array(ITEM_APPROVED => 0, ITEM_DELETED => 0, ITEM_UNAPPROVED => 0);
		foreach (array(965400 => array(1,1,1), 965420 => array(1,1), 965440 => array(1), 965460 => array(1,2,0),
			965480 => array(1,1), 965500 => array(1,1), 965520 => array(1,1,1,1)) as $id => $statuses)
		{
			$end = $id + count($statuses) - 1;
			$ts = time() - 120;
			$n = array_count_values($statuses);
			$t = array_replace($topic, array('topic_id'=>$id, 'forum_id'=>15, 'topic_poster'=>$member, 'topic_title'=>'Confirmed topic ' . $id,
				'topic_status'=>ITEM_UNLOCKED,'topic_visibility'=>ITEM_APPROVED,'topic_first_post_id'=>$id,'topic_last_post_id'=>$end,
				'topic_posts_approved'=>$n[ITEM_APPROVED], 'topic_posts_softdeleted'=>$n[ITEM_DELETED] ?? 0, 'topic_posts_unapproved'=>$n[ITEM_UNAPPROVED] ?? 0,
				'topic_time'=>$ts,'topic_last_post_time'=>$ts+count($statuses),'topic_type'=>POST_NORMAL,'topic_moved_id'=>0,'plus_legacy_removed'=>0,
				'plus_description'=>'Historical description ' . $id, 'poll_title'=>'', 'poll_start'=>0,'poll_last_vote'=>0,'topic_attachment'=>0,'icon_id'=>0));
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
			foreach ($statuses as $offset => $vis)
			{
				$pid = $id + $offset;
				$p = array_replace($post, array('post_id'=>$pid,'forum_id'=>15,'topic_id'=>$id,'poster_id'=>$member,'post_time'=>$ts+$offset,
					'post_visibility'=>$vis,'plus_public_post'=>(int) ($vis !== ITEM_UNAPPROVED),'plus_legacy_erased'=>0,'post_edit_locked'=>0,
					'post_subject'=>'Subject ' . $pid, 'post_text'=>'<t>Contents ' . $pid . '</t>','post_edit_count'=>1,'post_edit_user'=>2,
					'post_delete_user'=>$vis === ITEM_DELETED ? 2 : 0,'post_delete_time'=>$vis === ITEM_DELETED ? 123456789 : 0,
					'post_delete_reason'=>$vis === ITEM_DELETED ? 'Existing native reason' : '', 'post_attachment'=>0,'post_postcount'=>0));
				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
				++$counts[$vis];
			}
		}
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_topics_approved=forum_topics_approved+7,
			forum_posts_approved=forum_posts_approved+' . $counts[ITEM_APPROVED] . ',forum_posts_unapproved=forum_posts_unapproved+1,
			forum_posts_softdeleted=forum_posts_softdeleted+1 WHERE forum_id=15');
		$config->increment('num_posts',$counts[ITEM_APPROVED],false);
		$config->increment('num_topics',7,false);
		$config->set('plus_test_erased_post_total',(int) $config['num_posts'],false);
		$config->set('plus_test_erased_topic_total',(int) $config['num_topics'],false);
		foreach (array(965420,965460) as $id)
		{
			$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET poll_start=10000000,poll_title='Poll $id' WHERE topic_id=$id");
			$db->sql_query('INSERT INTO ' . POLL_OPTIONS_TABLE . " (poll_option_id,topic_id,poll_option_text,poll_option_total) VALUES (1,$id,'Option',1)");
			$db->sql_query('INSERT INTO ' . POLL_VOTES_TABLE . " (topic_id,poll_option_id,vote_user_id,vote_user_ip) VALUES ($id,1,2,'192.0.2.1')");
		}
		$source = deleted_test_rows('SELECT * FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=50')[0];
		foreach (array(965400 => array(965400,'plus_deleted_shared.gif',false), 965420 => array(965420,'plus_deleted_shared.gif',false),
			965500 => array(965500,'plus_deleted_solo.gif',true)) as $id => $file)
		{
			$a = array_replace($source,array('attach_id'=>$id,'topic_id'=>$id,'post_msg_id'=>$file[0],'physical_filename'=>$file[1],
				'real_filename'=>$file[1],'filesize'=>4,'in_message'=>0,'is_orphan'=>0,'poster_id'=>$member,'thumbnail'=>(int) $file[2]));
			$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $a));
			$db->sql_query('UPDATE ' . POSTS_TABLE . " SET post_attachment=1 WHERE post_id=$id");
			$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_attachment=1 WHERE topic_id=$id");
			file_put_contents($dir . $file[1], 'GIF8');
			if ($file[2]) { file_put_contents($dir . 'thumb_' . $file[1], 'GIF8'); }
		}
		$config->increment('num_files',2,false);
		$config->increment('upload_dir_size',8,false);
		$db->sql_query('INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text,word_common,word_count) VALUES ('confirmederasureprobe',0,2)");
		$word = (int) $db->sql_nextid();
		$db->sql_query('INSERT INTO ' . SEARCH_WORDMATCH_TABLE . " (word_id,post_id,title_match) VALUES ($word,965402,0),($word,965401,0)");
		$db->sql_query('INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text,word_common,word_count) VALUES ('confirmederasurebatch',0,3)");
		$word = (int) $db->sql_nextid();
		$db->sql_query('INSERT INTO ' . SEARCH_WORDMATCH_TABLE . " (word_id,post_id,title_match) VALUES ($word,965521,0),($word,965522,0),($word,965523,0)");
		$db->sql_query('INSERT INTO ' . $table_prefix . "plus_bylines (post_id,author_name,editor_name,author_clean,author_key)
			VALUES (965402,'Retired confirmation fixture','Edit confirmation fixture','retired confirmation fixture','')");
		$config->set('plus_post_delete_policy',0);
		break;
	case 'unchanged':
		deleted_test_marker(965402,false);
		break;
	case 'author':
		deleted_test_marker(965402,true);
		deleted_test_marker(965401,false);
		deleted_test_expect(!deleted_test_rows('SELECT post_id FROM ' . $table_prefix . 'plus_bylines WHERE post_id=965402') &&
			!deleted_test_rows('SELECT post_id FROM ' . SEARCH_WORDMATCH_TABLE . ' WHERE post_id=965402') &&
			deleted_test_rows('SELECT post_id FROM ' . SEARCH_WORDMATCH_TABLE . ' WHERE post_id=965401'), 'Erased byline or search word survived or a healthy hit was removed');
		break;
	case 'mcp-post':
		deleted_test_marker(965420,true);
		deleted_test_marker(965421,false);
		deleted_test_files($dir,true,true);
		deleted_test_expect((int) $quota->occupied($member,false) === (int) $config['plus_test_erased_quota']+8 &&
			(int) $config['num_files'] === (int) $config['plus_test_erased_files']+2 &&
			(int) $config['upload_dir_size'] === (int) $config['plus_test_erased_bytes']+8, 'Removing one shared association released the healthy file allocation');
		deleted_test_expect(!deleted_test_rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=965420') &&
			deleted_test_rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=965400') &&
			!deleted_test_rows('SELECT topic_id FROM ' . POLL_OPTIONS_TABLE . ' WHERE topic_id=965420') &&
			!deleted_test_rows('SELECT topic_id FROM ' . POLL_VOTES_TABLE . ' WHERE topic_id=965420') &&
			deleted_test_topic(965420)['poll_title'] === '', 'The native poll or attachment of the opening post was not detached independently');
		break;
	case 'allow': $config->set('plus_post_delete_policy',1); break;
	case 'multiple':
		deleted_test_marker(965520,false);
		deleted_test_marker(965521,true);
		deleted_test_marker(965522,true);
		deleted_test_marker(965523,false);
		$word = deleted_test_rows('SELECT w.word_count,m.post_id FROM ' . SEARCH_WORDLIST_TABLE . ' w JOIN ' . SEARCH_WORDMATCH_TABLE .
			" m ON m.word_id=w.word_id WHERE w.word_text='confirmederasurebatch'");
		deleted_test_expect(count($word) === 1 && (int) $word[0]['post_id'] === 965523 && (int) $word[0]['word_count'] === 1,
			'A bulk deletion removed another post or miscounted a shared indexed word');
		break;
	case 'native-policy': $config->set('plus_post_delete_policy',-1); break;
	case 'native':
		$p = deleted_test_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=965481')[0];
		deleted_test_expect(!$p['plus_legacy_erased'] && (int) $p['post_visibility'] === ITEM_DELETED && strpos($p['post_text'], 'Contents 965481') !== false,
			'An installation using native moderation erased recoverable content');
		$config->set('plus_post_delete_policy',0);
		break;
	case 'topics':
		foreach (array(965420,965440) as $id)
		{
			$t = deleted_test_topic($id);
			deleted_test_expect(\phpbb2plus\community\service\removed_topics::marker($t) && $t['plus_description'] === '' && !$t['topic_last_post_subject'],
				'An entirely confirmed deletion retained a subject or lost its locked topic');
		}
		deleted_test_marker(965440,true);
		deleted_test_marker(965460,true);
		deleted_test_expect((int) deleted_test_topic(965460)['topic_visibility'] !== ITEM_APPROVED &&
			(int) $config['num_posts'] === (int) $config['plus_test_erased_post_total']-7 &&
			(int) $config['num_topics'] === (int) $config['plus_test_erased_topic_total']-3, 'Neutral positions increased native public totals');
		$p = deleted_test_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE topic_id=965460 AND post_id<>965460 ORDER BY post_id');
		deleted_test_expect((int) $p[0]['post_visibility'] === ITEM_DELETED && $p[0]['post_delete_reason'] === 'Existing native reason' &&
			(int) $p[0]['post_delete_time'] === 123456789 && !$p[0]['plus_legacy_erased'] && strpos($p[0]['post_text'],'965461') !== false &&
			(int) $p[1]['post_visibility'] === ITEM_UNAPPROVED && !$p[1]['plus_legacy_erased'] && strpos($p[1]['post_text'],'965462') !== false &&
			!deleted_test_topic(965460)['plus_legacy_removed'], 'A pre-existing moderation action or private submission changed');
		break;
	case 'transaction':
		$id = 965500;
		$allow = static function ($topic) { return (int) $topic['topic_id'] === 965500 && (int) $topic['forum_id'] === 15; };
		deleted_test_expect(!$service->prune_topic($id,15,2,time(),static function () { return false; }), 'A rejected maintenance predicate changed a topic');
		$db->sql_transaction('begin');
		deleted_test_expect($service->prune_topic($id,15,2,time(),$allow) === array('topics'=>1,'posts'=>2), 'Trusted maintenance did not count the locked published posts');
		deleted_test_marker($id,true);
		deleted_test_files($dir,true,true);
		deleted_test_expect(deleted_test_rows("SELECT attach_id FROM $queue WHERE attach_id=965500"), 'The commit did not journal its physical file');
		$db->sql_transaction('rollback');
		deleted_test_marker($id,false);
		deleted_test_files($dir,true,true);
		deleted_test_expect(!deleted_test_rows("SELECT attach_id FROM $queue WHERE attach_id=965500") &&
			deleted_test_rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=965500'), 'Rolling back lost the original attachment');
		$db->sql_transaction('begin');
		deleted_test_expect($service->prune_topic($id,15,2,time(),$allow), 'Maintenance retry failed');
		$db->sql_transaction('commit');
		// End this process immediately after committing the outer transaction.
		break;
	case 'restore':
		$native->set_topic_visibility(ITEM_APPROVED,965440,15,2,time(),'Restore from native moderation');
		deleted_test_expect(\phpbb2plus\community\service\removed_topics::marker(deleted_test_topic(965440)), 'Restoration published an irreversibly erased topic');
		$native->set_post_visibility(ITEM_APPROVED,array(965460,965461),965460,15,2,time(),'',false,false);
		deleted_test_marker(965460,true);
		$p = deleted_test_rows('SELECT post_visibility,post_text FROM ' . POSTS_TABLE . ' WHERE post_id=965461')[0];
		deleted_test_expect((int) $p['post_visibility'] === ITEM_APPROVED && strpos($p['post_text'],'965461') !== false &&
			(int) deleted_test_rows('SELECT post_visibility FROM ' . POSTS_TABLE . ' WHERE post_id=965462')[0]['post_visibility'] === ITEM_UNAPPROVED,
			'Restoring a real moderator deletion affected an erased post or private draft');
		break;
	case 'resume':
		deleted_test_files($dir,true,true);
		deleted_test_expect(deleted_test_rows("SELECT attach_id FROM $queue WHERE attach_id=965500"), 'Interrupted cleanup was not durable');
		$cron = $phpbb_container->get('phpbb2plus.community.erased_files_cron');
		deleted_test_expect($cron->should_run(), 'An interrupted cleanup was not scheduled');
		$cron->run();
		$cron->run();
		deleted_test_files($dir,true,false);
		deleted_test_expect((int) $quota->occupied($member,false) === (int) $config['plus_test_erased_quota']+4 &&
			(int) $config['num_files'] === (int) $config['plus_test_erased_files']+1 &&
			(int) $config['upload_dir_size'] === (int) $config['plus_test_erased_bytes']+4, 'The committed removal did not release its storage and owner quota');
		deleted_test_expect(!deleted_test_rows("SELECT attach_id FROM $queue WHERE attach_id=965500"), 'Cleanup did not finish idempotently');
		break;
	default: exit(2);
}
