<?php
namespace phpbb2plus\community\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class delegation_report extends Command
{
	private $db;
	private $table;

	public function __construct($db, $table)
	{
		$this->db = $db;
		$this->table = $table;
		parent::__construct();
	}

	protected function configure()
	{
		$this->setName('phpbb2plus:delegation:report')->setDescription('List converted Junior Admin module decisions as JSON Lines.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$result = $this->db->sql_query("SELECT source_user,user_id,account_state,report_json FROM {$this->table} ORDER BY source_user");
		while ($row = $this->db->sql_fetchrow($result))
		{
			$output->writeln(json_encode(array('source_user' => (int) $row['source_user'], 'user_id' => (int) $row['user_id'],
				'account' => $row['account_state'], 'modules' => json_decode($row['report_json'], true) ?? array())), OutputInterface::OUTPUT_RAW);
		}
		$this->db->sql_freeresult($result);
		return 0;
	}
}
