<?php
namespace phpbb2plus\community\cron;

class daily_visitors extends \phpbb\cron\task\base
{
	private $visitors;
	public function __construct($visitors) { $this->visitors = $visitors; }
	public function should_run() { return $this->visitors->due(); }
	public function run() { $this->visitors->cleanup(); }
}
