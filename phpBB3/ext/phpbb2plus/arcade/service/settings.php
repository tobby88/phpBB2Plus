<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class settings
{
	// Effective fallback, minimum and maximum for each control. Other imported values stay with their modules.
	const GROUPS = array(
		'access'=>array('games_offline'=>array(1,0,1), 'games_no_guests'=>array(1,0,1), 'games_posts_required'=>array(0,0,2147483647),
			'games_mod_ban_users'=>array(1,0,1), 'games_use_log'=>array(0,0,1)),
		'catalogue'=>array('games_per_page'=>array(25,0,100), 'games_show_all'=>array(1,0,1), 'games_show_played'=>array(1,0,1),
			'games_show_fav'=>array(1,0,1), 'games_show_stats'=>array(1,0,1), 'games_total_top'=>array(10,1,100),
			'games_new_games'=>array(1,0,1), 'games_at_highscore'=>array(1,0,1),
			'games_show_mhm'=>array(1,0,1), 'games_guest_highscore'=>array(0,0,1)),
		'community'=>array('games_rate'=>array(0,0,1), 'games_default_rate'=>array(0,0,255), 'games_comments'=>array(0,0,1),
			'games_comment_size'=>array(0,0,65535), 'games_use_pms'=>array(0,0,1), 'games_pm_comment'=>array(0,0,1)),
		'tournaments'=>array('games_tournament_mode'=>array(0,0,1), 'games_tournament_user'=>array(1,0,1),
			'games_tournament_max'=>array(10,1,10000), 'games_tournament_games'=>array(6,1,10000), 'games_tournament_players'=>array(12,2,10000)),
	);

	private $db;
	private $catalog;
	private $table;

	public function __construct($db, $catalog, $table)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->table = $table;
	}

	public function snapshot($group)
	{
		$values = array();
		foreach (self::GROUPS[$group] as $name=>$bounds) { $values[$name] = $this->catalog->setting($name, null); }
		return $values;
	}

	public static function revision($values) { return hash('sha256', serialize($values)); }

	/** Called inside the Arcade transaction. Each independently submitted section has its own revision. */
	public function save($group, array $values, $revision)
	{
		if (!$this->catalog->admin()) { throw new AccessDeniedHttpException(); }
		$old = $this->snapshot($group);
		if (!hash_equals(self::revision($old), $revision)) { throw new \DomainException('PLUS_ARC_CONFIG_CHANGED'); }
		$changed = false;
		foreach (self::GROUPS[$group] as $name=>$bounds)
		{
			$value = $values[$name] ?? '';
			if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) || (float) $value < $bounds[1] || (float) $value > $bounds[2])
			{ throw new \DomainException('PLUS_ARC_CONFIG_INVALID'); }
			if ($value === (string) ($old[$name] ?? $bounds[0])) { continue; }
			$row = array('config_value'=>$value);
			if ($old[$name] === null)
			{
				$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row + array('config_name'=>$name)));
			}
			else
			{
				$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $row) .
					" WHERE config_name='" . $this->db->sql_escape($name) . "'");
			}
			$changed = true;
		}
		$this->catalog->refresh();
		return $changed;
	}
}
