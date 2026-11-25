<?php
namespace phpbb2plus\community\cron;

class erased_files extends \phpbb\cron\task\base
{
	private $files;
	public function __construct($files) { $this->files = $files; }
	public function should_run() { return $this->files->available(); }
	public function run() { $this->files->drain(); }
}
