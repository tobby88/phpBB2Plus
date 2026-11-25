<?php
namespace phpbb2plus\community\cron;

class age_pruning extends \phpbb\cron\task\base
{
	private $pruning;
	public function __construct($pruning) { $this->pruning = $pruning; }
	public function should_run() { return $this->pruning->available(); }
	public function run() { $this->pruning->resume(); }
}
