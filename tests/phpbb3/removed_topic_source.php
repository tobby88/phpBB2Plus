<?php
function seed_removed_topics($db)
{
	$now = time() - 120;
	foreach (array(805100 => array(15, 805200, 42), 805101 => array(7, 805250, 1)) as $id => $source)
	{
		list($forum, $first, $count) = $source;
		$last = $first + $count - 1;
		$replies = $count - 1;
		$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_desc,topic_poster,topic_time,topic_status,
			topic_replies,topic_first_post_id,topic_last_post_id) VALUES ($id,$forum,'Gelöschtes Thema','',-1,$now,1,$replies,$first,$last)");
		for ($post = $first; $post <= $last; ++$post)
		{
			$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time,post_deleted,post_deleted_time,post_deleted_by)
				VALUES ($post,$id,$forum,-1,'',$now,1,$now,2)");
			// This explicitly deleted row simulates an incomplete historical wipe.
			$body = $post === $first ? 'UnrecoverableMarkerFixture' : '';
			$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES ($post,'','$body')");
		}
	}
	$db->query("INSERT INTO phpbb_attachments_desc (attach_id,physical_filename,real_filename,download_count,extension,mimetype,filesize,filetime,thumbnail)
		VALUES (48,'unlinked.gif','Retired-file.gif',0,'gif','image/gif',0,10000000,0)");
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_replies,topic_first_post_id,topic_last_post_id)
		VALUES (805104,7,'Zero contents fixture',-1,$now,1,805299,805300)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time,post_deleted,post_deleted_time,post_deleted_by)
		VALUES (805299,805104,7,-1,'',$now,1,$now,2)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (805299,'','')");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time)
		VALUES (805300,805104,7,89,'c0000203',$now)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (805300,'Visible zero','0')");
	$db->query("INSERT INTO phpbb_attachments (attach_id,post_id,privmsgs_id,user_id_1,user_id_2)
		VALUES (48,31,0,-1,0),(48,805200,0,-1,0),(48,805300,0,89,0)");
	foreach (array(805100,805104) as $topic)
	{
		$db->query("UPDATE phpbb_topics SET topic_vote=1 WHERE topic_id=$topic");
		$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length) VALUES ($topic,$topic,'Erased ballot fixture',$now,0)");
		$db->query("INSERT INTO phpbb_vote_results (vote_id,vote_option_id,vote_option_text,vote_result) VALUES ($topic,1,'Residual option',1)");
		$db->query("INSERT INTO phpbb_vote_voters (vote_id,vote_user_id,vote_user_ip) VALUES ($topic,2,'c0000209')");
	}
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_desc,topic_status,topic_moved_id,topic_time)
		VALUES (805106,14,'Old erased move link','Old link details',2,805100,10000),
		(805107,14,'Old healthy move link','Kept link details',2,1,10000)");
}
