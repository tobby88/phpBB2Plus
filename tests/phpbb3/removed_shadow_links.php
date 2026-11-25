<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function shadow_test_row($sql)
{
	global $db;
	$r = $db->sql_query($sql);
	$row = $db->sql_fetchrow($r);
	$db->sql_freeresult($r);
	return $row;
}
function shadow_test_topic($id) { return shadow_test_row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $id); }
function shadow_test_source()
{
	$old = shadow_test_topic(805106);
	$other = shadow_test_topic(805107);
	if (!$old || (int) $old['topic_status'] !== ITEM_MOVED || (int) $old['topic_moved_id'] !== 805100 || (int) $old['topic_visibility'] !== ITEM_DELETED ||
		!in_array($old['topic_title'],\phpbb2plus\community\service\removed_topics::TITLES,true) || $old['plus_description'] !== '' ||
		!$other || (int) $other['topic_visibility'] !== ITEM_APPROVED || $other['topic_title'] !== 'Old healthy move link')
	{ throw new RuntimeException('An imported move link stayed public or a healthy move link was altered'); }
}
switch ($argv[2])
{
	case 'source':
		shadow_test_source();
		break;
	case 'upgrade':
		$tools = $phpbb_container->get('dbal.tools');
		$class = \phpbb2plus\community\migrations\v136_removed_topic_shadows::class;
		$mt = $table_prefix . 'migrations';
		$name = shadow_test_row("SELECT migration_name FROM $mt WHERE " . $db->sql_in_set('migration_name', array($class,'\\' . $class)))['migration_name'];
		$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_visibility=" . ITEM_APPROVED . ",topic_title='Old erased move link',
			plus_description='Old link details' WHERE topic_id=805106");
		// Imported databases can also contain occupied or independently moderated
		// move rows. Neither is an ordinary published, empty redirect.
		$alias = shadow_test_topic(805106);
		foreach (array(805108=>ITEM_APPROVED,805109=>ITEM_DELETED) as $id=>$vis)
		{
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT',array_replace($alias,array(
				'topic_id'=>$id,'topic_visibility'=>$vis,'topic_title'=>'Independently retained ' . $id))));
		}
		$p = shadow_test_row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=805300');
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT',array_replace($p,array(
			'post_id'=>805310,'topic_id'=>805108,'forum_id'=>$alias['forum_id'],'post_text'=>'<t>Independent native contents</t>'))));
		$config->increment('num_topics',1,false);
		$tools->sql_index_drop(TOPICS_TABLE,'plus_moved_target');
		$db->sql_query("DELETE FROM $mt WHERE migration_name='" . $db->sql_escape($name) . "'");
		$migrator = $phpbb_container->get('migrator');
		$migrator->load_migration_state();
		$migrator->set_migrations(array($name));
		for ($i=0; !$migrator->finished() && $i<8; ++$i) { $migrator->update(); }
		if (!$migrator->finished()) { throw new RuntimeException('Move link migration did not finish'); }
		shadow_test_source();
		$count = shadow_test_row('SELECT COUNT(*) AS total FROM ' . TOPICS_TABLE . ' WHERE topic_visibility=' . ITEM_APPROVED);
		if ((int) $count['total'] !== (int) $config['num_topics']) { throw new RuntimeException('Old move link still counted as a public topic'); }
		if (shadow_test_topic(805108)['topic_title'] !== 'Independently retained 805108' || shadow_test_topic(805109)['topic_title'] !== 'Independently retained 805109' ||
			shadow_test_row('SELECT post_text FROM ' . POSTS_TABLE . ' WHERE post_id=805310')['post_text'] !== '<t>Independent native contents</t>')
		{ throw new RuntimeException('The move link upgrade altered a populated or independently moderated record'); }
		$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id=805310 AND topic_id=805108');
		$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id IN (805108,805109) AND topic_moved_id=805100');
		$config->increment('num_topics',-1,false);
		require_once $phpbb_root_path . 'includes/functions_admin.' . $phpEx;
		sync('forum', 'forum_id', array((int) $alias['forum_id']), true);
		break;
	case 'seed':
		$t = shadow_test_topic(1);
		$p = shadow_test_row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1');
		$id = 965610;
		$now = time() - 120;
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT',array_replace($t,array(
			'topic_id'=>$id,'forum_id'=>15,'topic_time'=>$now,'topic_title'=>'Moved topic to remove','topic_first_post_id'=>$id,
			'topic_last_post_id'=>$id,'topic_last_post_time'=>$now,'topic_visibility'=>ITEM_APPROVED,'topic_status'=>ITEM_UNLOCKED,
			'topic_posts_approved'=>1,'topic_posts_unapproved'=>0,'topic_posts_softdeleted'=>0,'topic_moved_id'=>0,'plus_legacy_removed'=>0,
			'poll_title'=>'','poll_start'=>0,'poll_last_vote'=>0,'topic_attachment'=>0))));
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT',array_replace($p,array(
			'post_id'=>$id,'topic_id'=>$id,'forum_id'=>15,'post_time'=>$now,'post_visibility'=>ITEM_APPROVED,'plus_legacy_erased'=>0,'plus_public_post'=>1))));
		foreach (array(965611=>array($id,'Fresh move link to remove'),965612=>array(1,'Independent move link to keep')) as $shadow => $item)
		{
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT',array_replace($t,array(
				'topic_id'=>$shadow,'forum_id'=>14,'topic_time'=>$now,'topic_title'=>$item[1],'topic_visibility'=>ITEM_APPROVED,
				'topic_status'=>ITEM_MOVED,'topic_moved_id'=>$item[0], 'topic_last_post_time'=>$now, 'plus_description'=>$item[1], 'plus_legacy_removed'=>0))));
		}
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_topics_approved=forum_topics_approved+2 WHERE forum_id=14');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_topics_approved=forum_topics_approved+1,forum_posts_approved=forum_posts_approved+1 WHERE forum_id=15');
		$config->increment('num_posts',1,false);
		$config->increment('num_topics',3,false);
		$config->set('plus_test_shadow_topics',(int) $config['num_topics'],false);
		break;
	case 'removed':
		$a = shadow_test_topic(965611);
		$b = shadow_test_topic(965612);
		if ((int) $a['topic_visibility'] !== ITEM_DELETED || $a['plus_description'] !== '' || (int) $b['topic_visibility'] !== ITEM_APPROVED ||
			$b['topic_title'] !== 'Independent move link to keep' || (int) $config['num_topics'] !== (int) $config['plus_test_shadow_topics']-2)
		{ throw new RuntimeException('A confirmed topic deletion retained a published move link or counted the wrong topics'); }
		break;
	default: exit(2);
}
