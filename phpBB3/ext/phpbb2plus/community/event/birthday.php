<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\notification\birthday as greeting;
use phpbb2plus\community\profile\birthdate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class birthday implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $language;
	private $request;
	private $notifications;
	private $helper;

	public function __construct($db, $config, $user, $language, $request, $notifications, $helper)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->request = $request;
		$this->notifications = $notifications;
		$this->helper = $helper;
	}

	public static function getSubscribedEvents()
	{
		return array('core.page_header' => 'visit', 'core.acp_board_config_edit_add' => 'settings',
			'core.ucp_profile_info_modify_sql_ary' => 'profile', 'core.acp_users_profile_modify_sql_ary' => 'profile');
	}

	private function today()
	{
		return new \DateTimeImmutable('today', new \DateTimeZone($this->config['board_timezone']));
	}

	public static function anniversary($birthday, \DateTimeImmutable $today)
	{
		$parts = birthdate::parts($birthday, $today);
		if (!$parts) { return null; }
		list($day, $month, $born) = $parts;
		$year = (int) $today->format('Y');
		// In a non-leap year DateTime normalises 29 February to 1 March, as Plus did.
		$date = $today->setDate($year, $month, $day);
		if ($date > $today) { $date = $today->setDate(--$year, $month, $day); }
		return array('date' => $date, 'year' => $year, 'age' => $born ? $year - $born : null);
	}

	private function enabled()
	{
		return !empty($this->config['allow_birthdays']) && !empty($this->config['plus_birthday_greeting']) &&
			!empty($this->user->data['is_registered']);
	}

	public function visit()
	{
		if (!$this->enabled() || empty($this->config['allow_board_notifications']) || empty($this->config['load_notifications']) ||
			defined('IN_ADMIN') || defined('IN_INSTALL') || $this->request->is_ajax() ||
			$this->request->server('REQUEST_METHOD', '') !== 'GET') { return; }
		$account = $this->user->data;
		$next = (int) $account['plus_next_birthday'];
		$today = $this->today();
		if ($next > (int) $today->format('Y')) { return; }
		$birthday = self::anniversary($account['user_birthday'], $today);
		if (!$birthday || $birthday['year'] < $next) { return; }

		$id = (int) $account['user_id'];
		$this->db->sql_transaction('begin');
		try
		{
			// The stored schedule and date both have to still match this request's session row.
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_next_birthday = ' . ($birthday['year'] + 1) .
				' WHERE user_id = ' . $id . ' AND plus_next_birthday = ' . $next .
				" AND user_birthday = '" . $this->db->sql_escape($account['user_birthday']) . "'");
			if ($this->db->sql_affectedrows())
			{
				$this->notifications->delete_notifications(greeting::TYPE, $id);
				$this->notifications->add_notifications(greeting::TYPE, array('user_id' => $id));
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
	}

	public function profile($event)
	{
		$fields = $event['sql_ary'];
		if (!isset($fields['user_birthday'])) { return; }
		$account = isset($event['user_row']) ? $event['user_row'] : $this->user->data;
		if ($account['user_birthday'] === $fields['user_birthday']) { return; }
		$result = self::anniversary($fields['user_birthday'], $this->today());
		// A newly entered birth date starts with its next anniversary.
		$fields['plus_next_birthday'] = $result ? $result['year'] + 1 : 0;
		$event['sql_ary'] = $fields;
		$this->notifications->delete_notifications(greeting::TYPE, (int) $account['user_id']);
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'features') { return; }
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$field = array('plus_birthday_greeting' => array('lang' => 'PLUS_BIRTHDAY_SETTING', 'validate' => 'bool',
			'type' => 'radio:yes_no', 'explain' => true));
		$display['vars'] = phpbb_insert_config_array($display['vars'], $field, array('after' => 'allow_birthdays'));
		$event['display_vars'] = $display;
	}

	public function view()
	{
		if (!$this->enabled()) { throw new AccessDeniedHttpException(); }
		$today = $this->today();
		$birthday = self::anniversary($this->user->data['user_birthday'], $today);
		if (!$birthday) { throw new AccessDeniedHttpException(); }
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		$on_date = $birthday['date'] == $today;
		$key = $on_date ? 'PLUS_BIRTHDAY_TODAY' : 'PLUS_BIRTHDAY_PREVIOUS';
		$args = array();
		if ($birthday['age'] !== null) { $key .= '_AGE'; $args[] = $birthday['age']; }
		if (!$on_date)
		{
			// A date in the board's calendar must not slip a day in a visitor's own timezone.
			$date = $birthday['date'];
			$args[] = $this->language->lang('PLUS_BIRTHDAY_DATE', (int) $date->format('j'),
				$this->language->lang(array('datetime', $date->format('F'))));
		}
		$response = $this->helper->message($key, $args, 'PLUS_BIRTHDAY_TITLE');
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
