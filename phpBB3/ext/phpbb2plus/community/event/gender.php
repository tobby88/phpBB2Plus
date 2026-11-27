<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\profile\gender as field;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class gender implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $loaded = false;
	private $catalogue;

	public function __construct($db, $config, $user) { $this->db = $db; $this->config = $config; $this->user = $user; }
	public static function getSubscribedEvents() { return array('core.viewtopic_modify_post_row'=>array('topic', -150)); }

	public function topic($event)
	{
		$owner = field::owner($this->config);
		$cp = $event['cp_row'];
		if (!$owner || empty($cp['blockrow'])) { return; }
		$post = $event['post_row'];
		foreach ($cp['blockrow'] as $key => $entry)
		{
			if ($entry['PROFILE_FIELD_IDENT'] !== $owner[1] || $entry['PROFILE_FIELD_TYPE'] !== field::TYPE) { continue; }
			if (!$this->loaded)
			{
				$this->catalogue = field::catalogue($this->db, $this->config);
				$this->loaded = true;
			}
			if (!$this->catalogue) { return; } // A different native field may now use the same identifier.
			$value = (int) $entry['PROFILE_FIELD_VALUE_RAW'];
			if ((int) $event['poster_id'] > ANONYMOUS && empty($post['S_POST_HIDDEN']) && $value !== $this->catalogue['none'])
			{
				$choice = array_search($value, $this->catalogue['values'], true);
				$labels = field::labels($this->user->lang_name);
				if (!$choice || $entry['PROFILE_FIELD_VALUE'] !== $labels[$choice + 1]) { return; } // Preserve relabelled native text.
				$post['PLUS_GENDER_ICON'] = $choice === 1 ? 'male' : 'female';
				$post['PLUS_GENDER_LABEL'] = htmlspecialchars_decode($entry['PROFILE_FIELD_NAME'] . ': ' . $entry['PROFILE_FIELD_VALUE'], ENT_QUOTES);
			}
			unset($cp['blockrow'][$key]);
			$event['cp_row'] = $cp;
			$event['post_row'] = $post;
			return;
		}
	}
}
