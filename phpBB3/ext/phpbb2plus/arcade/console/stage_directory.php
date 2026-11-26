<?php
namespace phpbb2plus\arcade\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class stage_directory extends Command
{
	private $batches;
	public function __construct($batches) { $this->batches=$batches; parent::__construct(); }

	protected function configure()
	{
		$this->setName('phpbb2plus:arcade:stage-directory')->setDescription('Prepare local media for the Arcade batch import.')
			->addArgument('directory',InputArgument::REQUIRED,'Local directory with prepared media (includes one level of subdirectories)')
			->addOption('type',null,InputOption::VALUE_REQUIRED,'package (SWF and matching GIF), flash (SWF), gif, or jpg','package')
			->addOption('label',null,InputOption::VALUE_REQUIRED,'Display label for the ACP');
	}

	protected function execute(InputInterface $input,OutputInterface $output)
	{
		list($key,$items,$skipped) = $this->batches->prepare($input->getArgument('directory'),$input->getOption('type'),(string) $input->getOption('label'));
		$output->writeln("Batch ID: $key\nPrepared: $items; skipped: $skipped");
		return 0;
	}
}
