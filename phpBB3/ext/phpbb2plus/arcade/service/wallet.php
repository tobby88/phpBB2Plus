<?php
namespace phpbb2plus\arcade\service;

class wallet
{
	private $db;
	private $catalog;
	private $prefix;

	public function __construct($db, $catalog, $prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->prefix = $prefix;
	}

	private function enabled()
	{
		return (int) $this->catalog->setting('use_rewards_mod', 0) && ((int) $this->catalog->setting('use_point_system', 0) ||
			(int) $this->catalog->setting('use_cash_system', 0) || (int) $this->catalog->setting('use_allowance_system', 0));
	}

	public function account($id)
	{
		if (!$this->enabled() || $id <= ANONYMOUS) { return null; }
		$result = $this->db->sql_query('SELECT c.*, w.balance_text FROM ' . $this->prefix . 'currencies c LEFT JOIN ' . $this->prefix .
			'wallets w ON w.currency_field=c.currency_field AND w.owner_id=' . (int) $id . ' WHERE c.is_active=1');
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	private function apply($id, $amount)
	{
		$account = $this->account($id);
		if (!$account) { throw new \DomainException('PLUS_ARC_WALLET'); }
		$scale = (int) $account['fraction_digits'];
		$old = currency::scaled($account['balance_text'] ?? $account['initial_amount'], $scale);
		$delta = currency::scaled($amount, $scale);
		$new = currency::add($old, $delta);
		if ($delta[0] === '-' && $new[0] === '-') { throw new \DomainException('PLUS_ARC_FUNDS'); }
		$table = $this->prefix . 'wallets';
		$row = array('balance_text' => currency::amount($new, $scale));
		if ($account['balance_text'] === null)
		{
			$row += array('owner_id' => (int) $id, 'currency_field' => $account['currency_field']);
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
		}
		else
		{
			$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $row) . ' WHERE owner_id=' . (int) $id .
				" AND currency_field='" . $this->db->sql_escape($account['currency_field']) . "'");
		}
	}

	public function charge($game, $id)
	{
		if ($this->enabled() && (int) $game['game_charge'] > 0) { $this->apply($id, '-' . $game['game_charge']); }
	}

	public function reward($game, $id, $units, $current, $all)
	{
		if (!$this->enabled() || $id <= ANONYMOUS) { return; }
		$bonus = ($current ? (int) $game['game_bonus'] : 0) + ($all ? (int) $game['at_game_bonus'] : 0);
		$amount = currency::reward($units, (int) $game['game_reward'], $bonus);
		if ($amount !== '0' && $amount[0] !== '-') { $this->apply($id, $amount); }
	}
}
