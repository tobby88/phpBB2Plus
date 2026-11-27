<?php
namespace phpbb2plus\community\service;

class removed_topics
{
	public const TITLES = array('Deleted topic', 'Gelöschtes Thema');

	public static function marker($row)
	{
		return !empty($row['plus_legacy_removed']) && (int) $row['topic_status'] === ITEM_LOCKED &&
			(int) $row['topic_visibility'] === ITEM_DELETED;
	}

	public static function condition($alias)
	{
		return $alias . 'plus_legacy_removed=1 AND ' . $alias . 'topic_status=' . ITEM_LOCKED .
			' AND ' . $alias . 'topic_visibility=' . ITEM_DELETED;
	}

	public static function classify($db, $prefix, $scope, $verified_source)
	{
		// A new conversion has a positive deletion flag on every source post. An
		// earlier conversion also needs the distinctive already-erased native rows.
		$posts = POSTS_TABLE;
		$files = ATTACHMENTS_TABLE;
		$refs = $prefix . 'plus_attach_refs';
		$where = $scope;
		$bad = 'p.forum_id<>t.forum_id OR p.post_visibility<>' . ITEM_DELETED . ' OR p.plus_public_post<>1';
		if (!$verified_source)
		{
			$bad .= ' OR p.poster_id<>' . ANONYMOUS . " OR p.post_username<>'' OR p.post_subject<>''
				OR BINARY p.post_text NOT IN ('', '<t></t>', '<t/>') OR p.post_delete_reason<>''";
			$where .= ' AND t.plus_legacy_removed=0 AND t.topic_visibility=' . ITEM_DELETED . " AND t.plus_description='' AND t.poll_title='' AND t.poll_start=0
				AND NOT EXISTS (SELECT 1 FROM " . POLL_OPTIONS_TABLE . " o WHERE o.topic_id=t.topic_id)
				AND NOT EXISTS (SELECT 1 FROM " . POLL_VOTES_TABLE . " v WHERE v.topic_id=t.topic_id)
				AND NOT EXISTS (SELECT 1 FROM $files a WHERE a.topic_id=t.topic_id AND a.in_message=0 AND a.is_orphan=0
					AND NOT EXISTS (SELECT 1 FROM $refs r WHERE r.attach_id=a.attach_id AND r.parent_id=a.post_msg_id AND r.in_message=0))";
		}
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' t SET t.plus_legacy_removed=1,t.topic_visibility=' . ITEM_DELETED .
			',t.topic_poster=' . ANONYMOUS . ',t.topic_last_poster_id=' . ANONYMOUS . ",
			t.topic_first_poster_name='',t.topic_last_poster_name='',t.topic_last_post_subject='',t.topic_first_poster_colour='',
			t.topic_last_poster_colour='',t.plus_description='',t.topic_attachment=0
			WHERE $where AND t.topic_moved_id=0 AND t.topic_status=" . ITEM_LOCKED . ' AND ' . $db->sql_in_set('t.topic_title', self::TITLES) .
			" AND EXISTS (SELECT 1 FROM $posts p WHERE p.topic_id=t.topic_id AND p.forum_id=t.forum_id)
			AND NOT EXISTS (SELECT 1 FROM $posts p WHERE p.topic_id=t.topic_id AND ($bad))");
		$db->sql_query("UPDATE $posts p JOIN " . TOPICS_TABLE . " t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			SET p.plus_legacy_erased=1 WHERE $scope AND t.plus_legacy_removed=1 AND p.post_visibility=" . ITEM_DELETED . ' AND p.plus_public_post=1');
	}

	public static function clear_posts($db, $prefix)
	{
		$last = 0;
		$processed = false;
		do
		{
			$result = $db->sql_query_limit('SELECT post_id FROM ' . POSTS_TABLE .
				' WHERE plus_legacy_erased=1 AND post_visibility=' . ITEM_DELETED . " AND post_id>$last ORDER BY post_id", 200);
			$candidates = array_column($db->sql_fetchrowset($result), 'post_id');
			$db->sql_freeresult($result);
			if (!$candidates) { break; }
			$last = (int) end($candidates);
			$where = $db->sql_in_set('p.post_id', $candidates) . ' AND p.plus_legacy_erased=1 AND p.post_visibility=' . ITEM_DELETED;
			$db->sql_transaction('begin');
			try
			{
				$result = $db->sql_query('SELECT p.post_id,p.topic_id,p.forum_id,p.poster_id FROM ' . POSTS_TABLE . " p WHERE $where FOR UPDATE");
				$rows = array_column($db->sql_fetchrowset($result), null, 'post_id');
				$db->sql_freeresult($result);
				if (!$rows) { $db->sql_transaction('commit'); continue; }
				$processed = true;
				// Keep mismatched attachments as unowned orphan copies, retaining the
				// source references and every healthy association of a shared file.
				$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' a JOIN ' . POSTS_TABLE . " p ON p.post_id=a.post_msg_id
					JOIN {$prefix}plus_attach_refs r ON r.attach_id=a.attach_id AND r.parent_id=p.post_id AND r.in_message=0
					SET a.is_orphan=1,a.poster_id=0,a.post_msg_id=0,a.topic_id=0 WHERE $where AND a.in_message=0 AND a.is_orphan=0 AND a.topic_id=p.topic_id");
				self::erase_rows($db, $prefix, $rows);
				$db->sql_transaction('commit');
			}
			catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
		}
		while (count($candidates) === 200);
		return $processed;
	}

	/** Both callers supply locked, positively identified rows after handling their attachment policy. */
	public static function erase_rows($db, $prefix, $rows)
	{
		$ids = array_keys($rows);
		$topics = array_unique(array_column($rows, 'topic_id'));
		$where = $db->sql_in_set('p.post_id', $ids) . ' AND p.plus_legacy_erased=1 AND p.post_visibility=' . ITEM_DELETED;
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' p SET p.poster_id=' . ANONYMOUS . ",
			p.post_username='',p.poster_ip='',p.post_subject='',p.post_text='',p.post_checksum='',p.post_edit_reason='',
			p.post_edit_user=0,p.post_edit_count=0,p.post_edit_time=0,p.post_attachment=0,p.icon_id=0,
			p.enable_sig=0,p.enable_bbcode=0,p.enable_smilies=0,p.enable_magic_url=0,p.bbcode_uid='',p.bbcode_bitfield=''
			WHERE $where");
		$db->sql_query("DELETE b FROM {$prefix}plus_bylines b JOIN " . POSTS_TABLE . " p ON p.post_id=b.post_id WHERE $where");
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' t SET t.topic_attachment=EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE .
			' a WHERE a.topic_id=t.topic_id AND a.in_message=0 AND a.is_orphan=0) WHERE ' . $db->sql_in_set('t.topic_id', $topics));
		self::remove_existing_matches($db, $rows, $ids);
	}

	private static function remove_existing_matches($db, $rows, $ids)
	{
		global $phpbb_root_path, $phpEx, $auth, $config, $user, $phpbb_dispatcher;
		// A previously converted board may already have indexed now-empty rows.
		$result = $db->sql_query('SELECT DISTINCT post_id FROM ' . SEARCH_WORDMATCH_TABLE . ' WHERE ' . $db->sql_in_set('post_id', $ids));
		$indexed = array_column($db->sql_fetchrowset($result), 'post_id');
		$db->sql_freeresult($result);
		if (!$indexed) { return; }
		// The native bulk removal subtracts once per word in titles and once
		// in bodies. Account for the additional posts in this batch first.
		if (count($indexed) > 1)
		{
			$db->sql_query('UPDATE ' . SEARCH_WORDLIST_TABLE . ' w JOIN (SELECT word_id,COUNT(*)-COUNT(DISTINCT title_match) AS extra
				FROM ' . SEARCH_WORDMATCH_TABLE . ' WHERE ' . $db->sql_in_set('post_id', $indexed) . ' GROUP BY word_id HAVING extra>0) d ON d.word_id=w.word_id
				SET w.word_count=GREATEST(0,CAST(w.word_count AS SIGNED)-CAST(d.extra AS SIGNED))');
		}
		$error = false;
		$index = new \phpbb\search\fulltext_native($error, $phpbb_root_path, $phpEx, $auth, $config, $db, $user, $phpbb_dispatcher);
		$matched = array_intersect_key($rows, array_flip($indexed));
		$index->index_remove($indexed, array_unique(array_column($matched, 'poster_id')), array_unique(array_column($matched, 'forum_id')));
	}

	public static function refresh_counts($db, $config)
	{
		foreach (array('num_posts' => array(POSTS_TABLE, 'post_visibility'), 'num_topics' => array(TOPICS_TABLE, 'topic_visibility')) as $key => $source)
		{
			$r = $db->sql_query("SELECT COUNT(*) AS total FROM {$source[0]} WHERE {$source[1]}=" . ITEM_APPROVED);
			$config->set($key, (int) $db->sql_fetchfield('total'), false);
			$db->sql_freeresult($r);
		}
		$r = $db->sql_query('SELECT COUNT(*) AS files,SUM(' . $db->cast_expr_to_bigint('filesize') . ') AS bytes FROM ' .
			ATTACHMENTS_TABLE . ' WHERE is_orphan=0');
		$files = $db->sql_fetchrow($r);
		$db->sql_freeresult($r);
		$config->set('num_files', (int) $files['files'], false);
		$config->set('upload_dir_size', (string) ($files['bytes'] ?? '0'), false);
	}
}
