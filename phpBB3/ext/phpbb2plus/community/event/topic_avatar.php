<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class topic_avatar implements EventSubscriberInterface
{
	private $config;
	private $db;
	private $user;
	private $language;
	private $paths;
	private $root;
	private $image;

	public function __construct($config,$db,$user,$language,$paths,$root)
	{
		$this->config=$config;
		$this->db=$db;
		$this->user=$user;
		$this->language=$language;
		$this->paths=$paths;
		$this->root=$root;
	}

	public static function getSubscribedEvents()
	{
		return array('core.acp_board_config_edit_add'=>'settings', 'core.viewtopic_post_rowset_data'=>'candidate',
			'core.viewtopic_modify_post_row'=>'display', 'core.ucp_profile_avatar_sql'=>'new_avatar',
			'core.acp_users_avatar_sql'=>'new_avatar', 'core.avatar_manager_avatar_delete_after'=>'deleted_avatar');
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'avatar' || !isset($this->config['plus_topic_default_avatar'])) { return; }
		$this->language->add_lang('topic_avatar','phpbb2plus/community');
		$display = $event['display_vars'];
		$field = array('plus_topic_default_avatar'=>array('lang'=>'PLUS_TOPIC_DEFAULT_AVATAR','validate'=>'bool','type'=>'radio:yes_no','explain'=>true));
		$display['vars'] = phpbb_insert_config_array($display['vars'],$field,array('after'=>'allow_avatar'));
		$event['display_vars'] = $display;
	}

	public function candidate($event)
	{
		$author = $event['row'];
		if ((string) $author['user_avatar'] !== '' || !empty($author['user_avatar_type']) || !empty($author['plus_no_default_avatar'])) { return; }
		$row = $event['rowset_data'];
		$row['plus_default_avatar_candidate'] = true;
		$event['rowset_data'] = $row;
	}

	private function image()
	{
		if ($this->image !== null) { return $this->image; }
		$this->image = '';
		$selected = (string) ($this->config['plus_topic_avatar_image'] ?? '');
		$names = array('default_avatar.gif'); // Optional, site-owned replacement, untouched by upgrades and imports.
		if ($selected === 'stock.gif' || preg_match('/^[a-f0-9]{64}\.gif$/D',$selected)) { $names[] = $selected; }
		foreach ($names as $name)
		{
			$relative = 'images/plusavatars/' . $name;
			$path = $this->root . $relative;
			if (!is_file($path) || is_link($path)) { continue; }
			$size = @getimagesize($path);
			if (!$size || $size[2] !== IMAGETYPE_GIF || !$size[0] || !$size[1]) { continue; }
			$url = utf8_htmlspecialchars($this->paths->get_web_root_path() . $relative);
			$this->image = '<img class="avatar plus-default-avatar" src="' . $url . '" width="' . (int) $size[0] .
				'" height="' . (int) $size[1] . '" alt="">';
			break;
		}
		return $this->image;
	}

	public function display($event)
	{
		$row = $event['row'];
		$post = $event['post_row'];
		if (empty($row['plus_default_avatar_candidate']) || (int) $row['post_visibility'] === ITEM_DELETED || !empty($post['S_POST_HIDDEN']) ||
			!empty($post['POSTER_AVATAR']) || empty($this->config['plus_topic_default_avatar']) || empty($this->config['allow_avatar']) ||
			empty($this->config['allow_avatar_local']) || !$this->user->optionget('viewavatars')) { return; }
		$post['POSTER_AVATAR'] = $this->image();
		$event['post_row'] = $post;
	}

	public function new_avatar($event)
	{
		if (!isset($this->config['plus_topic_default_avatar'])) { return; }
		$values = $event['result'];
		$values['plus_no_default_avatar'] = 0;
		$event['result'] = $values;
	}

	public function deleted_avatar($event)
	{
		if (isset($this->config['plus_topic_default_avatar']) && $event['table'] === USERS_TABLE && $event['prefix'] === 'user_')
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_no_default_avatar=0 WHERE plus_no_default_avatar=1 AND user_id=' .
				(int) $event['avatar_data']['id']);
		}
	}
}
