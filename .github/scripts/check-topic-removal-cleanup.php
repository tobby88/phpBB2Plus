<?php
require __DIR__ . '/check-posting-lifecycle.php';
function removal_fixture()
{
	posting_fixture(); $p=$GLOBALS['mutation_server']->pdo;
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_moved_id) VALUES (200,3,100),(201,4,100),(202,4,999)');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (14,201,4)');
	foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table)
	{ $p->exec('INSERT INTO '.$table.' VALUES (200,8),(200,9),(201,8),(202,8)'); }
}
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	removal_fixture(); posting_delete();
	foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table)
	{
		mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id IN (100,200)')===0,'Removed topic and empty redirect lose dependent records: '.$table);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id IN (201,202)')===2,'Nonempty/foreign redirects keep dependent records: '.$table);
	}
	posting_fixture(); $reply=posting_submit('reply'); posting_delete($reply[0]);
	foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table)
	{ mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id=100')===1,'Deleting only a reply retains topic records: '.$table); }
	foreach(array('UPDATE fixture_topics SET forum_id=4 WHERE topic_id=200', 'UPDATE fixture_topics SET topic_moved_id=999 WHERE topic_id=200', 'INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (15,200,3)') as $change)
	{
		removal_fixture(); $changed=false;
		$mutation_server->hook=function($sql) use($change,&$changed)
		{
			if(!$changed && strpos($sql,'DELETE FROM fixture_topics WHERE topic_moved_id')===0) { $changed=true; $GLOBALS['mutation_server']->pdo->exec($change); }
		};
		posting_delete();
		mutation_check($changed && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=200')===1,'Changed redirect is not deleted');
		foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table)
		{ mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id=200')===2,'Changed redirect retains all dependent rows: '.$table); }
	}
	removal_fixture(); $added=false;
	$mutation_server->hook=function($sql) use(&$added)
	{
		if(!$added && strpos($sql,'DELETE FROM fixture_topics WHERE topic_moved_id')===0)
		{ $added=true; $GLOBALS['mutation_server']->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_moved_id) VALUES (203,3,100)'); }
	};
	posting_delete(); mutation_check($added && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=203')===1,'Cleanup does not sweep newly arriving unselected redirects');
	foreach(array('DELETE FROM fixture_topics WHERE topic_moved_id','DELETE FROM fixture_views WHERE topic_id = 200') as $failure)
	{
		removal_fixture(); $mutation_server->failure=$failure;
		mutation_expect_failure(function(){ posting_delete(); });
		mutation_check($mutation_server->owner===null,'Late cleanup failure releases shared writer lock');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_views WHERE topic_id=200')===2 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=201')===1,'Failed cleanup retains affected view rows and unrelated posts');
	}
	echo "Removed topic and redirect dependent-record cleanup checks passed.\n";
}
finally { if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
