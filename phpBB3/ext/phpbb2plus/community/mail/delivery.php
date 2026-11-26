<?php
namespace phpbb2plus\community\mail;

/** Native message::send uses independent recipient and sender envelopes. Keep their actual transport outcomes. */
class delivery extends \messenger
{
	public $accepted=false;
	public $copy_failed=false;
	private $attempts=0;
	private $board_address;

	public function __construct($board_address)
	{
		parent::__construct(false);
		$this->board_address=$board_address;
	}

	public function send($method=NOTIFY_EMAIL,$break=false)
	{
		// Do not send a misleading sender copy if the primary recipient could not be reached.
		if ($this->attempts && !$this->accepted) { return false; }
		$first=($this->attempts++===0);
		// phpBB resets its envelopes between recipients. Both use the configured outgoing board address.
		$this->from($this->board_address);
		try { $ok=parent::send($method,$break); }
		finally { if (!$first && empty($ok)) { $this->copy_failed=true; } }
		if ($first) { $this->accepted=(bool)$ok; }
		return $ok;
	}
}
