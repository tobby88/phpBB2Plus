<?php
// Called by the isolated print fixture on an installed, converted native board.
if (!defined('IN_PHPBB') || PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $dbhost !== '127.0.0.1' ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $dbname)) { exit(2); }
if ($argv[1] === 'markers-upgrade')
{
	$marker = print_fixture_row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=805100');
	$first = print_fixture_row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=805200');
	$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET is_orphan=0,post_msg_id=805200,topic_id=805100,poster_id=' . ANONYMOUS .
		' WHERE attach_id IN (SELECT attach_id FROM ' . $table_prefix . 'plus_attach_refs WHERE source_id=48 AND parent_id=805200)');
	$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_attachment=1 WHERE post_id=805200');
	$db->sql_query('INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text,word_common,word_count) VALUES ('markerupgradeword',0,2)");
	$word_id = (int) $db->sql_nextid();
	$db->sql_query('INSERT INTO ' . SEARCH_WORDMATCH_TABLE . " (word_id,post_id,title_match) VALUES ($word_id,32,0),($word_id,805200,0)");
	// Native never-published and formerly published recoverable deletions both
	// retain their current private moderation workflow even under the same title.
	$native_bodies = array(805103 => '<t>Native recoverable marker fixture</t>', 805000 => '<T/>');
	foreach (array(805102 => 0, 805103 => 1, 805105 => 1, 805000 => 1) as $id => $was_public)
	{
		$id_post = $id + 160;
		$t = array_replace($marker, array('topic_id' => $id, 'topic_first_post_id' => $id_post, 'topic_last_post_id' => $id_post,
			'topic_posts_approved' => 0, 'topic_posts_softdeleted' => 1, 'plus_legacy_removed' => 0));
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
		$p = array_replace($first, array('topic_id' => $id, 'post_id' => $id_post, 'plus_public_post' => $was_public,
			'post_text' => $native_bodies[$id] ?? ''));
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
	}
	$db->sql_query('INSERT INTO ' . POLL_OPTIONS_TABLE . " (topic_id,poll_option_id,poll_option_text,poll_option_total)
		VALUES (805105,1,'Recoverable native poll option',0)");
	$tools = $phpbb_container->get('dbal.tools');
	$table = $table_prefix . 'migrations';
	$class = \phpbb2plus\community\migrations\v132_removed_topics::class;
	$row = print_fixture_row('SELECT migration_name FROM ' . $table . ' WHERE ' . $db->sql_in_set('migration_name', array($class, '\\' . $class)));
	$name = $row['migration_name'];
	$tools->sql_index_drop(TOPICS_TABLE, 'plus_removed_forum');
	$tools->sql_column_remove(TOPICS_TABLE, 'plus_legacy_removed');
	$tools->sql_column_remove(POSTS_TABLE, 'plus_legacy_erased');
	$db->sql_query("DELETE FROM $table WHERE migration_name='" . $db->sql_escape($name) . "'");
	$migrator = $phpbb_container->get('migrator');
	$migrator->load_migration_state();
	$migrator->set_migrations(array($name));
	for ($step = 0; !$migrator->finished() && $step < 8; ++$step) { $migrator->update(); }
	if (!$migrator->finished()) { throw new RuntimeException('Incomplete historical marker upgrade'); }
	$word = print_fixture_row('SELECT w.word_count,COUNT(m.post_id) AS matches,MIN(m.post_id) AS surviving
		FROM ' . SEARCH_WORDLIST_TABLE . ' w LEFT JOIN ' . SEARCH_WORDMATCH_TABLE . " m ON m.word_id=w.word_id
		WHERE w.word_id=$word_id GROUP BY w.word_id,w.word_count");
	if ((int) $word['word_count'] !== 1 || (int) $word['matches'] !== 1 || (int) $word['surviving'] !== 32)
	{ throw new RuntimeException('The upgrade retained a stale index hit or removed a healthy hit on the same word'); }
	$db->sql_query('DELETE FROM ' . SEARCH_WORDMATCH_TABLE . " WHERE word_id=$word_id");
	$db->sql_query('DELETE FROM ' . SEARCH_WORDLIST_TABLE . " WHERE word_id=$word_id");
}
foreach (array(31,805200,805300) as $parent)
{
	$file = print_fixture_row('SELECT a.is_orphan,a.poster_id,a.topic_id,a.post_msg_id FROM ' . ATTACHMENTS_TABLE .
		' a JOIN ' . $table_prefix . "plus_attach_refs r ON r.attach_id=a.attach_id WHERE r.source_id=48 AND r.parent_id=$parent");
	$actual = array_map('intval', array_values($file));
	if ($actual !== ($parent === 805300 ? array(0,89,805104,805300) : array(1,0,0,0)))
	{ throw new RuntimeException('Erased and healthy references to the same attachment were not separated: ' . json_encode($actual)); }
}
if ($argv[1] === 'markers-import')
{
	// The native cron may reparse an empty post between conversion and this check.
	$reparser = $phpbb_container->get('text_reparser.post_text');
	$reparser->reparse_range(31, 31);
	$reparser->reparse_range(805201, 805201);
	foreach (array(805100,805104) as $id)
	{
		$poll = print_fixture_row('SELECT t.poll_title,t.poll_start,(SELECT COUNT(*) FROM ' . POLL_OPTIONS_TABLE . ' o WHERE o.topic_id=t.topic_id) AS options,
			(SELECT COUNT(*) FROM ' . POLL_VOTES_TABLE . ' v WHERE v.topic_id=t.topic_id) AS votes FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id=' . $id);
		if ($poll['poll_title'] !== '' || (int) $poll['poll_start'] || (int) $poll['options'] || (int) $poll['votes'])
		{ throw new RuntimeException('A historical poll survived its erased opening post'); }
	}
	$single = print_fixture_row('SELECT plus_legacy_erased,post_text,post_subject,post_edit_user,post_username,post_delete_user,
		(SELECT COUNT(*) FROM ' . $table_prefix . 'plus_bylines WHERE post_id=31) AS bylines,
		(SELECT COUNT(*) FROM ' . SEARCH_WORDMATCH_TABLE . ' WHERE post_id=31) AS words FROM ' . POSTS_TABLE . ' WHERE post_id=31');
	if ((int) $single['plus_legacy_erased'] !== 1 || !in_array($single['post_text'], array('', '<t></t>', '<t/>'), true) || $single['post_subject'] !== '' ||
		$single['post_username'] !== '' || (int) $single['post_edit_user'] || (int) $single['post_delete_user'] !== 2 ||
		(int) $single['bylines'] || (int) $single['words']) { throw new RuntimeException('A single erased source post retained historical content or lost its deletion record'); }
	foreach (array('num_posts' => array(POSTS_TABLE, 'post_visibility'), 'num_topics' => array(TOPICS_TABLE, 'topic_visibility')) as $key => $fields)
	{
		$row = print_fixture_row("SELECT COUNT(*) AS total FROM {$fields[0]} WHERE {$fields[1]}=" . ITEM_APPROVED);
		if ((int) $config[$key] !== (int) $row['total']) { throw new RuntimeException("Native $key total " . (int) $config[$key] . ' differs from ' . (int) $row['total']); }
	}
}
foreach (array(805100 => 42, 805101 => 1) as $id => $count)
{
	$t = print_fixture_row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $id);
	$state = print_fixture_row('SELECT COUNT(*) AS posts,SUM(plus_public_post) AS already_public,
		SUM(post_visibility=' . ITEM_DELETED . ") AS removed,SUM(BINARY post_text NOT IN ('','<t></t>','<t/>') OR post_subject<>'' OR post_username<>'' OR poster_id<>" . ANONYMOUS .
		') AS remaining FROM ' . POSTS_TABLE . ' WHERE topic_id=' . $id);
	$index = print_fixture_row('SELECT COUNT(*) AS words FROM ' . SEARCH_WORDMATCH_TABLE . ' w JOIN ' .
		POSTS_TABLE . ' p ON p.post_id=w.post_id WHERE p.topic_id=' . $id);
	if (!\phpbb2plus\community\service\removed_topics::marker($t) || (int) $t['topic_posts_approved'] !== 0 ||
		(int) $t['topic_posts_softdeleted'] !== $count || (int) $state['posts'] !== $count || (int) $state['already_public'] !== $count ||
		(int) $state['removed'] !== $count || (int) $state['remaining'] !== 0 || (int) $index['words'] !== 0)
	{
		throw new RuntimeException('A converted marker was published, lost, or retained source text: ' .
			json_encode(array('id'=>$id, 'tag'=>$t['plus_legacy_removed'], 'status'=>$t['topic_status'],
				'visibility'=>$t['topic_visibility'], 'approved'=>$t['topic_posts_approved'], 'softdeleted'=>$t['topic_posts_softdeleted'], 'state'=>$state)));
	}
}
if ($argv[1] === 'markers-upgrade')
{
	foreach (array(805102,805103,805105,805000) as $id)
	{
		$row = print_fixture_row('SELECT plus_legacy_removed FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $id);
		if ($row['plus_legacy_removed']) { throw new RuntimeException('A native recoverable deletion was classified as a historical marker'); }
	}
	$row = print_fixture_row('SELECT poll_option_text FROM ' . POLL_OPTIONS_TABLE . ' WHERE topic_id=805105');
	if ($row['poll_option_text'] !== 'Recoverable native poll option') { throw new RuntimeException('An existing native poll was discarded'); }
}
