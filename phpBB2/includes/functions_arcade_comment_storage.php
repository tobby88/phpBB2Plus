<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_rating_storage.php';

function phpbb_arcade_comment_tables()
{
	$tables = array_diff(phpbb_arcade_rating_tables(), array(iNA_GAMES_RATE));
	return array_merge(array_values($tables), array(iNA_GAMES_COMMENT, iNA_SCORES, iNA_AT_SCORES));
}
function phpbb_arcade_comment_id($id) { return phpbb_arcade_rating_id($id, min(4294967295, PHP_INT_MAX)); }
function phpbb_arcade_comment_named($row) { $out=array(); foreach($row as $key=>$value) { if(is_string($key)) { $out[$key]=$value; } } return $out; }
function phpbb_arcade_comment_manage($user, $comment, $game)
{
	return (int)$user['user_level'] === ADMIN || (int)$user['user_id'] === (int)$comment['comment_user_id']
		|| ((int)$game['mod_id'] > 0 && (int)$user['user_id'] === (int)$game['mod_id']);
}
function phpbb_arcade_comment_context($store, $actor, $game_id, $comment_id, $locked)
{
	$update = $locked ? ' FOR UPDATE' : ''; $comment = null;
	if ($comment_id)
	{
		$rows = $store->rows('SELECT * FROM ' . iNA_GAMES_COMMENT . ' WHERE comment_id=' . (int)$comment_id . $update);
		if (count($rows) !== 1 || !is_string($rows[0]['comment_game_name'])) { phpbb_arcade_rating_fail('no_comment_found'); }
		$comment = phpbb_arcade_comment_named($rows[0]); $name = $store->sql_escape($comment['comment_game_name']);
		$games = $store->rows('SELECT game_id FROM ' . iNA_GAMES . " WHERE game_name='$name'" . $update);
		if (count($games) !== 1 || ($game_id && (int)$game_id !== (int)$games[0]['game_id'])) { phpbb_arcade_rating_fail('no_comment_found'); }
		$game_id = (int)$games[0]['game_id'];
	}
	if (!phpbb_arcade_rating_id($game_id)) { phpbb_arcade_rating_fail('no_activity'); }
	$context = phpbb_arcade_rating_context($store, $actor, $game_id, $locked, 'games_comments');
	$context['comment'] = $comment; $context['can_manage'] = $comment && phpbb_arcade_comment_manage($context['user'], $comment, $context['game']);
	return $context;
}
function phpbb_arcade_comment_recent_score($store, $context, $locked)
{
	$name = $store->sql_escape($context['game']['game_name']); $id = (int)$context['user']['user_id']; $now = time();
	foreach (array(iNA_SCORES, iNA_AT_SCORES) as $table)
	{
		if ($store->rows('SELECT date FROM ' . $table . " WHERE game_name='$name' AND player_id=$id AND date>" . ($now-3600)
			. ' AND date<=' . ($now+60) . ' ORDER BY date DESC LIMIT 1' . ($locked ? ' FOR UPDATE' : ''))) { return true; }
	}
	return false;
}
class PhpbbArcadeCommentReader extends PhpbbForumReadSnapshot
{
	function __construct($database) { parent::__construct($database, phpbb_arcade_comment_tables()); }
}
function phpbb_arcade_comment_view($database, $actor, $game_id, $comment_id = 0)
{
	if (!PhpbbForumReadSnapshot::valid_actor($actor)) { phpbb_arcade_rating_fail('Session_invalid'); }
	if (($game_id && !phpbb_arcade_rating_id($game_id)) || ($comment_id && !phpbb_arcade_comment_id($comment_id)) || (!$game_id && !$comment_id)) { phpbb_arcade_rating_fail('no_activity'); }
	$reader = null;
	try
	{
		$reader = new PhpbbArcadeCommentReader($database); $context = phpbb_arcade_comment_context($reader, $actor, $game_id, $comment_id, false);
		$name = $reader->sql_escape($context['game']['game_name']);
		$count = $reader->rows('SELECT COUNT(*) total FROM ' . iNA_GAMES_COMMENT . " WHERE comment_game_name='$name'");
		$context['game']['comments_count'] = (int)$count[0]['total'];
		$first = $reader->rows('SELECT c.comment_user_id user_id,u.username FROM ' . iNA_GAMES_COMMENT . ' c LEFT JOIN ' . USERS_TABLE
			. " u ON u.user_id=c.comment_user_id WHERE c.comment_game_name='$name' ORDER BY c.comment_id LIMIT 1");
		$context['first'] = $first ? $first[0] : array('user_id'=>0,'username'=>'');
		$context['can_post'] = phpbb_arcade_comment_recent_score($reader, $context, false);
		return $context;
	}
	catch (PhpbbArcadeRatingFailure $error) { throw $error; }
	catch (Exception $error) { phpbb_arcade_rating_fail('arcade_comment_unavailable'); }
	catch (Error $error) { phpbb_arcade_rating_fail('arcade_comment_unavailable'); }
	finally { if ($reader) { $reader->release(); } }
}
class PhpbbArcadeCommentWriter extends PhpbbArcadeRatingWriter
{
	protected function storage_tables() { return phpbb_arcade_comment_tables(); }
	protected function storage_fail($key = 'arcade_comment_failed') { phpbb_arcade_rating_fail($key === 'Owned_storage_upgrade' ? 'arcade_comment_unavailable' : 'arcade_comment_failed'); }
	function change($action, $game_id, $comment_id, $raw, $text)
	{
		$context = phpbb_arcade_comment_context($this, $this->actor, $game_id, $comment_id, true);
		if ($action !== 'add' && !$context['can_manage']) { phpbb_arcade_rating_fail('Not_Authorised'); }
		if ($action === 'add' && !phpbb_arcade_comment_recent_score($this, $context, true)) { phpbb_arcade_rating_fail('Not_Authorised'); }
		if ($action !== 'delete' && strlen($raw) > $context['comment_limit']) { phpbb_arcade_rating_fail('to_much_comment_text'); }
		$before = $context['comment']; $id = (int)$context['user']['user_id']; $now = time();
		$notify = 0;
		if ($action === 'delete') { $this->query('DELETE FROM ' . iNA_GAMES_COMMENT . ' WHERE comment_id=' . (int)$comment_id); }
		elseif ($action === 'edit')
		{
			if ((int)$before['comment_edit_count'] >= 65535) { $this->storage_fail(); }
			$this->query('UPDATE ' . iNA_GAMES_COMMENT . " SET comment_text='" . $this->escape($text) . "',comment_edit_time=$now,comment_edit_count=comment_edit_count+1,comment_edit_user_id=$id WHERE comment_id=" . (int)$comment_id);
			$before['comment_text']=$text; $before['comment_edit_time']=(string)$now; $before['comment_edit_count']=(string)((int)$before['comment_edit_count']+1); $before['comment_edit_user_id']=(string)$id;
		}
		else
		{
			$username = $context['user']['username'];
			if (!is_string($username) || strlen($username)>128 || strpos($username,"\0")!==false || preg_match('//u',$username)!==1 || preg_match_all('/./us',$username,$ignored)>32) { $this->storage_fail(); }
			$name = $this->escape($context['game']['game_name']); $ip = $this->escape($context['ip']); $user = $this->escape($username);
			$first = $this->rows('SELECT comment_user_id FROM ' . iNA_GAMES_COMMENT . " WHERE comment_game_name='$name' ORDER BY comment_id LIMIT 1 FOR UPDATE");
			if ($context['comment_notify'] && $first && (int)$first[0]['comment_user_id']>0 && (int)$first[0]['comment_user_id']!==$id) { $notify=(int)$first[0]['comment_user_id']; }
			$this->query('INSERT INTO ' . iNA_GAMES_COMMENT . " (comment_game_name,comment_user_id,comment_username,comment_user_ip,comment_time,comment_text) VALUES('$name',$id,'$user','$ip',$now,'" . $this->escape($text) . "')");
			$last = $this->rows('SELECT LAST_INSERT_ID() id'); $comment_id = $last[0]['id'];
			if (!phpbb_arcade_comment_id($comment_id)) { $this->storage_fail(); }
			$before = array('comment_id'=>(string)$comment_id,'comment_game_name'=>$context['game']['game_name'],'comment_user_id'=>(string)$id,'comment_username'=>$username,'comment_user_ip'=>$context['ip'],'comment_time'=>(string)$now,'comment_text'=>$text,'comment_edit_time'=>null,'comment_edit_count'=>'0','comment_edit_user_id'=>null,'game_name'=>null);
		}
		$saved = $this->rows('SELECT * FROM ' . iNA_GAMES_COMMENT . ' WHERE comment_id=' . (int)$comment_id);
		if ($action === 'delete' ? count($saved)!==0 : (count($saved)!==1 || ($action==='add' ? array_intersect_key(phpbb_arcade_comment_named($saved[0]),$before) : phpbb_arcade_comment_named($saved[0]))!==$before)) { $this->storage_fail(); }
		$current = phpbb_arcade_comment_context($this,$this->actor,(int)$context['game']['game_id'],0,true);
		if ($current['game']['game_name']!==$context['game']['game_name'] || ($action!=='add' && !phpbb_arcade_comment_manage($current['user'],$context['comment'],$current['game']))
			|| ($action==='add' && !phpbb_arcade_comment_recent_score($this,$current,true)) || ($action!=='delete' && strlen($raw)>$current['comment_limit'])) { $this->storage_fail(); }
		$this->commit_attempted=true; $this->commit();
		return array('comment_id'=>(int)$comment_id,'game_id'=>(int)$current['game']['game_id'],'cat_id'=>(int)$current['game']['cat_id'],'notify_user'=>$notify,'game_name'=>$current['game']['game_name']);
	}
}
function phpbb_arcade_comment_change($database, $actor, $action, $game_id, $comment_id = 0, $raw = '', $text = '')
{
	if (!PhpbbForumReadSnapshot::valid_actor($actor)) { phpbb_arcade_rating_fail('Session_invalid'); }
	if (!in_array($action,array('add','edit','delete'),true) || ($game_id && !phpbb_arcade_rating_id($game_id))
		|| ($action==='add' ? ($comment_id || !$game_id) : !phpbb_arcade_comment_id($comment_id))) { phpbb_arcade_rating_fail('Not_Authorised'); }
	if ($action!=='delete' && (!is_string($raw)||trim($raw)===''||strlen($raw)>65535||preg_match('//u',$raw)!==1||!is_string($text)||$text===''||strlen($text)>65535||preg_match('//u',$text)!==1)) { phpbb_arcade_rating_fail('no_comment_text'); }
	$writer = null;
	try { $writer = new PhpbbArcadeCommentWriter($database,$actor); return $writer->change($action,$game_id,$comment_id,$raw,$text); }
	catch (Exception $error) { if ($writer && $writer->commit_attempted) { phpbb_arcade_rating_fail('arcade_comment_unconfirmed'); } if ($error instanceof PhpbbArcadeRatingFailure) { throw $error; } phpbb_arcade_rating_fail('arcade_comment_failed'); }
	catch (Error $error) { phpbb_arcade_rating_fail($writer && $writer->commit_attempted ? 'arcade_comment_unconfirmed' : 'arcade_comment_failed'); }
	finally { if ($writer) { $writer->release(); } }
}
function phpbb_arcade_comment_message($error, $limit = 0)
{
	global $lang; $key = $error instanceof PhpbbArcadeRatingFailure ? $error->getMessage() : 'arcade_comment_failed';
	if ($key==='arcade_rating_unavailable') { $key='arcade_comment_unavailable'; }
	if ($key==='to_much_comment_text' && isset($lang[$key])) { return sprintf($lang[$key],(int)$limit); }
	if (in_array($key,array('Session_invalid','Not_Authorised','no_activity','no_comment_found','does_not_exist','no_comment_text','arcade_comment_unavailable','arcade_comment_failed','arcade_comment_unconfirmed'),true) && isset($lang[$key])) { return $lang[$key]; }
	return isset($lang['arcade_comment_failed']) ? $lang['arcade_comment_failed'] : 'Comments are currently unavailable.';
}
