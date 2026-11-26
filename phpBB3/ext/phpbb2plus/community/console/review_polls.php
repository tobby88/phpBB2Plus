<?php
namespace phpbb2plus\community\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class review_polls extends Command
{
	private $db, $prefix;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->prefix = $prefix;
		parent::__construct();
	}

	protected function configure()
	{
		$this->setName('phpbb2plus:recovery:polls')->setDescription('List source polls held for review as JSON Lines.')
			->addArgument('poll-id', InputArgument::OPTIONAL, 'Show the original question, each option and the number of recorded participations.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$id = $input->getArgument('poll-id');
		if ($id !== null && (!ctype_digit($id) || (string) (int) $id !== $id))
		{
			$output->writeln('<error>Enter a numeric source poll ID.</error>');
			return 1;
		}
		$p = $this->prefix;
		if ($id === null)
		{
			$result = $this->db->sql_query("SELECT source_id,topic_id,reason FROM {$p}plus_poll_review ORDER BY source_id");
			while ($row = $this->db->sql_fetchrow($result))
			{
				$output->writeln(json_encode(array('source_id' => (int) $row['source_id'], 'topic_id' => (int) $row['topic_id'],
					'reason' => $row['reason']), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
			}
			$this->db->sql_freeresult($result);
			return 0;
		}
		$result = $this->db->sql_query("SELECT h.topic_id,h.reason,h.header_json,
			(SELECT COUNT(*) FROM {$p}plus_poll_receipts r WHERE r.source_id=h.source_id) AS receipts
			FROM {$p}plus_poll_review h WHERE h.source_id=" . (int) $id);
		$header = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$header)
		{
			$output->writeln('<error>No poll with this source ID is awaiting review.</error>');
			return 1;
		}
		$record = array('source_id' => (int) $id, 'topic_id' => (int) $header['topic_id'], 'reason' => $header['reason'],
			'receipts' => (int) $header['receipts']) + json_decode($header['header_json'], true, 512, JSON_THROW_ON_ERROR);
		$output->writeln(json_encode($record, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
		$result = $this->db->sql_query("SELECT choice_json FROM {$p}plus_poll_choices WHERE source_id=" . (int) $id . ' ORDER BY position');
		while ($row = $this->db->sql_fetchrow($result)) { $output->writeln($row['choice_json'], OutputInterface::OUTPUT_RAW); }
		$this->db->sql_freeresult($result);
		return 0;
	}
}
