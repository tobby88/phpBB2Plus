<?php
namespace phpbb2plus\community\cron;

class announcements extends \phpbb\cron\task\base
{
	private $announcements;
	public function __construct($announcements) { $this->announcements=$announcements; }
	public function should_run() { return $this->announcements->should_sweep(); }
	public function run() { $this->announcements->sweep(); }
}
