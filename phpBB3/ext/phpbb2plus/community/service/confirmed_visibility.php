<?php
namespace phpbb2plus\community\service;

/** Keep the native visibility and moderation APIs; only confirmed Plus deletions open the extra transaction. */
class confirmed_visibility extends \phpbb\content_visibility
{
	private $deletions;
	public function set_deletions($deletions) { $this->deletions = $deletions; }

	public function set_post_visibility($visibility, $post_id, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility = false, $limit_delete_time = false)
	{
		if (in_array((int) $visibility, array(ITEM_APPROVED, ITEM_REAPPROVE), true))
		{
			return $this->deletions->restore(false, $post_id, $topic_id, false, $limit_visibility, $limit_delete_time,
				function ($ids) use ($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility, $limit_delete_time) {
					return parent::set_post_visibility($visibility, $ids, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility, $limit_delete_time);
				});
		}
		$nested = $this->deletions->topic_children($topic_id, $forum_id, $user_id, $visibility);
		if ($nested && $post_id === false)
		{
			// The topic method calls its post method internally. Limit that call to
			// the published IDs locked before phpBB made its own selection.
			return parent::set_post_visibility($visibility, $nested, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest);
		}
		if (!$this->deletions->matches(false, $post_id, $topic_id, $forum_id, $user_id, $visibility))
		{
			return parent::set_post_visibility($visibility, $post_id, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility, $limit_delete_time);
		}
		return $this->deletions->change(false, $post_id, $topic_id, $forum_id, $user_id, $time,
			function ($ids, $topic) use ($visibility, $topic_id, $forum_id, $user_id, $time, $reason) {
				return parent::set_post_visibility($visibility, $ids, $topic_id, $forum_id, $user_id, $time, $reason,
					in_array((int) $topic['topic_first_post_id'], $ids, true), in_array((int) $topic['topic_last_post_id'], $ids, true));
			});
	}

	public function set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all = false)
	{
		if (in_array((int) $visibility, array(ITEM_APPROVED, ITEM_REAPPROVE), true))
		{
			return $this->deletions->restore(true, false, $topic_id, $force_update_all || (int) $visibility !== ITEM_APPROVED, false, false,
				function () use ($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all) {
					return parent::set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all);
				});
		}
		if ($force_update_all || !$this->deletions->matches(true, false, $topic_id, $forum_id, $user_id, $visibility))
		{
			return parent::set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all);
		}
		return $this->deletions->change(true, false, $topic_id, $forum_id, $user_id, $time,
			function () use ($visibility, $topic_id, $forum_id, $user_id, $time, $reason) {
				return parent::set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason);
			});
	}
}
