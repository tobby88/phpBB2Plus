<?php
namespace phpbb2plus\community\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class review_polls extends Command
{
	private $recovery;

	public function __construct($recovery)
	{
		$this->recovery = $recovery;
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
		if ($id === null)
		{
			foreach ($this->recovery->listing() as $row)
			{
				$output->writeln(json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
			}
			return 0;
		}
		$header = $this->recovery->header($id);
		if (!$header)
		{
			$output->writeln('<error>No poll with this source ID is awaiting review.</error>');
			return 1;
		}
		unset($header['choices']);
		$output->writeln(json_encode($header, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
		foreach ($this->recovery->choices($id) as $row)
		{
			$output->writeln(json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
		}
		return 0;
	}
}
