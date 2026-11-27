<?php
namespace phpbb2plus\colours\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $palette;
	private $template;

	public function __construct($palette,$template)
	{
		$this->palette = $palette;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.group_add_user_after'=>'membership', 'core.group_delete_user_after'=>'membership',
			'core.user_set_default_group'=>'membership', 'core.user_set_group_attributes'=>'approval',
			'core.user_add_after'=>'new_account', 'core.index_modify_page_title'=>'legend',
			'core.viewforum_modify_page_title'=>array('category_legend', -10));
	}

	public function membership($event) { $this->palette->refresh((array) $event['user_id_ary']); }
	public function new_account($event) { $this->palette->refresh(array($event['user_id'])); }
	public function approval($event) { if ($event['action'] === 'approve') { $this->membership($event); } }
	public function category_legend()
	{
		if ($this->template->retrieve_var('S_PLUS_CATEGORY_STATS')) { $this->legend(); }
	}

	public function legend()
	{
		$entries = array();
		foreach ($this->palette->visible() as $row)
		{
			$style = preg_match('/^[a-fA-F0-9]{6}$/D',$row['colour']) ? ' style="color:#' . $row['colour'] . '"' : '';
			// Palette names use phpBB's escaped text convention, also used for group names.
			$entries[] = '<span class="username-coloured plus-colour-legend"' . $style . '>' . $row['name'] . '</span>';
		}
		if ($entries)
		{
			$native = $this->template->retrieve_var('LEGEND');
			if ($native) { $entries[] = $native; }
			$this->template->assign_var('LEGEND',implode(', ', $entries));
		}
	}
}
