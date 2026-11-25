<?php
namespace phpbb2plus\banners\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $catalog;
	private $db;
	private $prefix;
	public function __construct($catalog, $db, $prefix)
	{
		$this->catalog = $catalog;
		$this->db = $db;
		$this->prefix = $prefix;
	}
	public static function getSubscribedEvents()
	{
		return array('core.page_header_after'=>'context', 'core.page_footer_after'=>'footer', 'core.delete_user_before'=>'accounts',
			'core.session_gc_after'=>'expire_receipts');
	}
	public function context($event) { $this->catalog->context($event['item'] === 'forum' ? $event['item_id'] : 0); }
	public function footer($event)
	{
		// The native exception page renders its template after closing the database.
		if (!$event['display_template']) { $this->catalog->skip_display(); }
	}
	public function accounts($event)
	{
		$ids = array_map('intval', array_keys($event['user_rows']));
		if (!$ids) { return; }
		$this->db->sql_query('UPDATE ' . $this->prefix . 's SET owner_id=0 WHERE ' . $this->db->sql_in_set('owner_id', $ids));
		$this->db->sql_query('UPDATE ' . $this->prefix . '_hits SET user_id=' . ANONYMOUS . ' WHERE ' . $this->db->sql_in_set('user_id', $ids));
	}
	public function expire_receipts()
	{
		$this->db->sql_query('DELETE FROM ' . $this->prefix . '_receipts WHERE expires<=' . time());
	}
}
