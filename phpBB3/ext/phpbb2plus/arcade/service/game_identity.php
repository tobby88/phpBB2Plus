<?php
namespace phpbb2plus\arcade\service;

/** Score receipts select a game by native ID. A name only confirms the already selected game. */
class game_identity
{
	// The thirteenth reference in phpBB2 was its expired, name-keyed session table.
	// Native launches are bound to the game ID, board session and account instead.
	private const REFERENCES=array('scores'=>'game_name','all_scores'=>'game_name','favorites'=>'fav_game_name',
		'comments'=>array('comment_game_name','game_name'),'ratings'=>'rate_game_name','monthly'=>'highscore_game',
		'bans'=>'game','tour_games'=>'game_name','tour_plays'=>'last_played_game','players'=>'last_played','categories'=>'last_game');
	private const RANK_LISTS=array('first_list','second_list','third_list','at_first_list','at_second_list','at_third_list');
	private $db;
	private $prefix;

	public function __construct($db,$prefix) { $this->db=$db; $this->prefix=$prefix; }

	public static function original($game)
	{
		return isset($game['protocol_name']) && $game['protocol_name']!=='' ? $game['protocol_name'] : (string)$game['game_name'];
	}

	public static function for_launch($game,$launch)
	{
		return isset($launch['start_name']) && $launch['start_name']!=='' ? $launch['start_name'] : (string)$game['game_name'];
	}

	public static function accepts($name,$game,$launch)
	{
		foreach (array_unique(array((string)$game['game_name'],self::original($game),self::for_launch($game,$launch))) as $trusted)
		{
			if ($name===$trusted || $name===html_entity_decode($trusted,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')) { return true; }
		}
		return false;
	}

	private function exists($lookups)
	{
		$result=$this->db->sql_query('SELECT ('.implode(' OR ',$lookups).') AS found');
		$used=(bool)$this->db->sql_fetchfield('found');
		$this->db->sql_freeresult($result);
		return $used;
	}

	public function available($name,$id=0,$history=true)
	{
		$variants=array_values(array_unique(array($name,html_entity_decode($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
			htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))));
		$name=$this->db->sql_escape($name);
		$id=(int)$id;
		$other=$id>0 ? ' AND game_id<>'.$id : '';
		// Retain the media's embedded identity and any protocol identity still in a valid launch.
		$lookups=array("EXISTS (SELECT 1 FROM {$this->prefix}games WHERE (".$this->db->sql_in_set('game_name',$variants).
			' OR '.$this->db->sql_in_set('protocol_name',$variants).")$other)",
			"EXISTS (SELECT 1 FROM {$this->prefix}launches WHERE ".$this->db->sql_in_set('start_name',$variants)."$other AND expires_at>".time().')');
		if ($history)
		{
			foreach (self::REFERENCES as $suffix=>$columns)
			{
				$where=array();
				foreach ((array)$columns as $column) { $where[]="$column='$name'"; }
				$lookups[]="EXISTS (SELECT 1 FROM {$this->prefix}$suffix WHERE ".implode(' OR ',$where).')';
			}
		}
		if ($this->exists($lookups)) { throw new \DomainException('PLUS_ARC_ADD_IDENTITY'); }
	}

	private function ranked_name($name)
	{
		$token=$this->db->sql_escape('|'.$name.'|');
		$terms=array();
		foreach (self::RANK_LISTS as $field) { $terms[]="LOCATE(BINARY '$token',BINARY CONCAT('|',$field,'|'))>0"; }
		return '('.implode(' OR ',$terms).')';
	}

	private function rename_rank_entries($old,$new)
	{
		// Legacy first/second/third place lists contained pipe-delimited names.
		// Some include places from scores which are no longer in the catalogue.
		$table=$this->prefix.'players'; $fields=implode(',',self::RANK_LISTS); $after=0;
		$where=$this->ranked_name($old);
		do
		{
			$result=$this->db->sql_query_limit("SELECT row_id,$fields FROM $table WHERE row_id>$after AND $where ORDER BY row_id",100);
			$rows=$this->db->sql_fetchrowset($result); $this->db->sql_freeresult($result);
			foreach ($rows as $row)
			{
				$after=(int)$row['row_id']; $changes=array();
				foreach (self::RANK_LISTS as $field)
				{
					$entries=explode('|',(string)$row[$field]);
					foreach (array_keys($entries,$old,true) as $index) { $entries[$index]=$new; }
					$updated=implode('|',$entries);
					if ($updated===(string)$row[$field]) { continue; }
					if (strlen($updated)>max(65535,strlen($row[$field]))) { throw new \DomainException('PLUS_ARC_NAME_LIST_FULL'); }
					$changes[$field]=$updated;
				}
				if ($changes) { $this->db->sql_query("UPDATE $table SET ".$this->db->sql_build_array('UPDATE',$changes)." WHERE row_id=$after"); }
			}
		} while (count($rows)===100);
	}

	/** Caller holds the Arcade coordinator, locked catalogue row and confirmed Edit grant. */
	public function rename($game,$new)
	{
		$id=(int)$game['game_id'];
		$old=(string)$game['game_name'];
		if ($old===$new) { return false; }
		if ($new==='' || strlen($new)>50 || !preg_match('~^[\pL\pM\pN._&+-]+$~uD',$new) || preg_match('~[\x{10000}-\x{10ffff}]~u',$new) ||
			$new==='.' || $new==='..') { throw new \DomainException('PLUS_ARC_NAME_INVALID'); }
		$from=$this->db->sql_escape($old); $to=$this->db->sql_escape($new);
		$games=$this->prefix.'games';
		if ($old==='' || strpos($old,'|')!==false || $this->exists(array("EXISTS (SELECT 1 FROM $games WHERE game_name='$from' AND game_id<>$id)")))
		{ throw new \DomainException('PLUS_ARC_NAME_AMBIGUOUS'); }
		// Existing scoreboard rows belong to this game when the two spellings
		// compare equal in the board's MySQL collation (including case-only renames).
		$same=$this->exists(array("EXISTS (SELECT 1 FROM $games WHERE game_id=$id AND game_name='$to')"));
		$this->available($new,$id,!$same);
		if (!$same && $this->exists(array("EXISTS (SELECT 1 FROM {$this->prefix}players WHERE ".$this->ranked_name($new).')')))
		{ throw new \DomainException('PLUS_ARC_ADD_IDENTITY'); }
		if (($game['protocol_name'] ?? '')==='')
		{
			$this->db->sql_query("UPDATE $games SET protocol_name='$from' WHERE game_id=$id");
		}
		// Before this migration active receipts have no start name. Pin it before changing the game.
		$this->db->sql_query("UPDATE {$this->prefix}launches SET start_name='$from' WHERE game_id=$id AND start_name='' AND expires_at>".time());
		foreach (self::REFERENCES as $suffix=>$columns)
		{
			foreach ((array)$columns as $column)
			{ $this->db->sql_query("UPDATE {$this->prefix}$suffix SET $column='$to' WHERE $column='$from'"); }
		}
		$this->rename_rank_entries($old,$new);
		$this->db->sql_query("UPDATE $games SET game_name='$to' WHERE game_id=$id");
		return true;
	}
}
