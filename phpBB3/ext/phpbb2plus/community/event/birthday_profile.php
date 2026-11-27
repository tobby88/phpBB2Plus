<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\profile\birthdate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class birthday_profile implements EventSubscriberInterface
{
	private $config;
	private $user;
	private $language;
	private $template;
	private $today;
	private $dates = array();

	public function __construct($config, $user, $language, $template)
	{
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.memberlist_view_profile'=>'profile', 'core.memberlist_prepare_profile_data'=>'age',
			'core.viewtopic_post_rowset_data'=>'poster_date', 'core.viewtopic_cache_user_data'=>'author',
			'core.viewtopic_modify_post_row'=>array('post', -150));
	}

	private function visible($person)
	{
		return !empty($this->config['allow_birthdays']) && !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) &&
			(int) ($person['user_id'] ?? 0) > ANONYMOUS && (int) ($person['user_type'] ?? USER_IGNORE) !== USER_IGNORE;
	}

	private function date($person)
	{
		if (!$this->visible($person) || empty($person['user_birthday'])) { return null; }
		$value = $person['user_birthday'];
		if (!array_key_exists($value, $this->dates))
		{
			if (!$this->today) { $this->today = new \DateTimeImmutable('today', new \DateTimeZone($this->config['board_timezone'])); }
			$this->dates[$value] = birthdate::parts($value, $this->today);
		}
		return $this->dates[$value];
	}

	private function sign($group, $key)
	{
		if (!$key) { return null; }
		$name = $this->language->lang(array('PLUS_BIRTHDAY_SIGNS', $key));
		$heading = $this->language->lang($group === 'zodiac' ? 'PLUS_BIRTHDAY_WESTERN' : 'PLUS_BIRTHDAY_CHINESE');
		return array('file'=>'icon_' . $group . '_' . $key . '.gif', 'name'=>$name, 'title'=>$heading . $this->language->lang('COLON') . ' ' . $name);
	}

	private function signs($parts)
	{
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		list($day, $month, $year) = $parts;
		return array('west'=>$this->sign('zodiac', birthdate::western($month, $day)),
			'east'=>$this->sign('chinese', birthdate::chinese($year, $month, $day)));
	}

	public function profile($event)
	{
		$person = $event['member'];
		$date = $this->date($person);
		if (!$date)
		{
			// The native age calculator consumes this row next. Withhold unavailable and invalid dates there too.
			$person['user_birthday'] = '';
			$event['member'] = $person;
		}
		if (!$this->visible($person)) { return; }
		$this->language->add_lang('ucp');
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		if (!$date) { $this->template->assign_var('PLUS_PROFILE_BIRTHDATE', $this->language->lang('PLUS_BIRTHDAY_UNSPECIFIED')); return; }
		list($day, $month, $year) = $date;
		$short_month = $this->today->setDate(2000, $month, $day)->format('M');
		$name = $this->language->lang(array('datetime', $short_month === 'May' ? 'May_short' : $short_month));
		$this->template->assign_vars(array(
			'PLUS_PROFILE_BIRTHDATE'=>$this->language->lang($year ? 'PLUS_PROFILE_BIRTHDATE_FULL' : 'PLUS_PROFILE_BIRTHDATE_PARTIAL', $day, $month, $name, $year),
			'PLUS_PROFILE_SIGNS'=>$this->signs($date),
		));
	}

	public function age($event)
	{
		$vars = $event['template_data'];
		if (!isset($vars['AGE']) || $vars['AGE'] === '') { return; }
		$date = $this->date($event['data']);
		if (!$date || !$date[2]) { $vars['AGE'] = ''; $event['template_data'] = $vars; }
	}

	public function poster_date($event)
	{
		$row = $event['row'];
		if (!$this->date($row)) { $row['user_birthday'] = ''; $event['row'] = $row; }
	}

	public function author($event)
	{
		$date = $this->date($event['row']);
		if (!$date) { return; }
		$cache = $event['user_cache_data'];
		$cache['plus_birthday_signs'] = $this->signs($date);
		$event['user_cache_data'] = $cache;
	}

	public function post($event)
	{
		$post = $event['post_row'];
		$signs = $event['user_cache'][$event['poster_id']]['plus_birthday_signs'] ?? null;
		if (!$signs || !empty($post['S_POST_HIDDEN']) || (int) $event['row']['post_visibility'] === ITEM_DELETED) { return; }
		$post['PLUS_BIRTHDAY_SIGNS'] = $signs;
		$event['post_row'] = $post;
	}
}
