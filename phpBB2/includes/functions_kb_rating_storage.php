<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';

// Voting shares the forum/KB writer owner: a vote cannot race article removal,
// consume a duplicate marker before failed statistics, or use stale visibility.
class PhpbbKbRatingDatabase extends PhpbbKbPublicationDatabase
{
	var $article_id;
	var $article = null;
	var $settings = array();
	function __construct($database,$id) { $this->article_id=$id; parent::__construct($database,'moderator'); }
	function write_tables() { return array(KB_ARTICLES_TABLE,KB_VOTES_TABLE); }
	function actor()
	{
		global $userdata;
		if (!$this->settings || !$this->article || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { kb_publication_fail('Not_Authorised'); }
		$guest=empty($userdata['session_logged_in']);
		if ($guest) {
			if (!isset($userdata['user_id']) || (string)$userdata['user_id']!==(string)ANONYMOUS) { kb_publication_fail('Not_Authorised'); }
			$user=array('user_id'=>ANONYMOUS,'user_level'=>USER);
		} else { $user=phpbb_current_moderator_user($this); if (!$user) { kb_publication_fail('Not_Authorised'); } }
		$id=(int)$user['user_id'];$sid=$this->sql_escape($userdata['session_id']);
		if (count($this->rows('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."') AND session_user_id=".$id.' AND session_logged_in='.($guest?0:1)))!==1) { kb_publication_fail('Not_Authorised'); }
		$moderator=!$guest&&(int)$user['user_level']===ADMIN;
		if (!$guest&&!$moderator&&$this->settings['mod_group']!=='0') {
			try { $group=kb_counter_category_id($this->settings['mod_group']); } catch (RuntimeException $error) { kb_publication_fail('Not_Authorised'); }
			$moderator=count($this->rows('SELECT g.group_id FROM '.GROUPS_TABLE.' g INNER JOIN '.USER_GROUP_TABLE.' m ON m.group_id=g.group_id WHERE g.group_id='.$group.' AND m.user_id='.$id.' AND m.user_pending=0'))===1;
		}
		$current=$this->rows('SELECT article_id,article_category_id,article_author_id,approved FROM '.KB_ARTICLES_TABLE.' WHERE article_id='.$this->article_id);
		if (count($current)!==1) { kb_publication_fail('Article_not_exsist'); }
		foreach(array('article_category_id','article_author_id','approved')as$key){if((string)$current[0][$key] !== (string)$this->article[$key]){kb_publication_fail('KB_rating_failed');}}
		if ($this->settings['allow_rating']!=='1'||($guest&&$this->settings['allow_anonymos_rating']!=='1')) { kb_publication_fail('Not_Authorised'); }
		if ((int)$this->article['approved']!==1&&!$moderator&&($guest||(int)$this->article['article_author_id']!==$id)) { kb_publication_fail('Article_not_exsist'); }
		return $user;
	}
	function begin()
	{
		$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION');$this->transactional=true;
		kb_publication_pin_tables($this,array_unique(array_merge($this->write_tables(),array(KB_CONFIG_TABLE,KB_CATEGORIES_TABLE,KB_TYPES_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE))));
		$columns=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$this->sql_escape(KB_ARTICLES_TABLE)."') AND COLUMN_NAME IN ('article_rating','article_totalvotes')");$types=array();foreach($columns as$column){$types[$column['COLUMN_NAME']]=$column;}
		if(count($types)!==2||strtolower($types['article_rating']['COLUMN_TYPE'])!=='decimal(30,4)'||preg_match('/^bigint(?:\([0-9]+\))? unsigned$/iD',$types['article_totalvotes']['COLUMN_TYPE'])!==1||$types['article_rating']['IS_NULLABLE']!=='NO'||$types['article_totalvotes']['IS_NULLABLE']!=='NO'||(string)$types['article_rating']['COLUMN_DEFAULT']!=='0.0000'||(string)$types['article_totalvotes']['COLUMN_DEFAULT']!=='0'){kb_publication_fail('KB_rating_upgrade');}
		foreach($this->rows('SELECT config_name,config_value FROM '.KB_CONFIG_TABLE.' ORDER BY config_name FOR UPDATE')as$row){$this->settings[$row['config_name']]=$row['config_value'];}
		foreach(array('allow_rating','allow_anonymos_rating','votes_check_ip','votes_check_userid')as$flag){if(!isset($this->settings[$flag])||!in_array($this->settings[$flag],array('0','1'),true)){kb_publication_fail('KB_rating_failed');}}
		if(!isset($this->settings['mod_group'])){kb_publication_fail('KB_rating_failed');}
		$rows=$this->rows('SELECT article_id,article_category_id,article_title,article_author_id,approved,article_rating,article_totalvotes FROM '.KB_ARTICLES_TABLE.' WHERE article_id='.$this->article_id.' FOR UPDATE');
		if(count($rows)!==1){kb_publication_fail('Article_not_exsist');}$this->article=$rows[0];
		if(!in_array((string)$this->article['approved'],array('0','1','2'),true)||count($this->rows('SELECT category_id FROM '.KB_CATEGORIES_TABLE.' WHERE category_id='.(int)$this->article['article_category_id'].' FOR UPDATE'))!==1){kb_publication_fail('Article_not_exsist');}
		$this->actor();
	}
}

function kb_rating_address($value)
{
	if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_IP)){kb_publication_fail('KB_rating_failed');}
	$packed=inet_pton($value);if($packed===false){kb_publication_fail('KB_rating_failed');}
	if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff"){$packed=substr($packed,12);}
	$hex=strtoupper(bin2hex($packed));$equivalents=array($hex);if(strlen($packed)===4){$equivalents[]='00000000000000000000FFFF'.$hex;}
	return array('address'=>inet_ntop($packed),'hex'=>$equivalents);
}

function kb_rate_article($database,$article_id,$rating)
{
	global $userdata,$client_ip;
	if(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||empty($userdata['session_id'])||!is_string($userdata['session_id'])||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($userdata['session_id'],$_POST['sid'])){kb_publication_fail('Session_invalid');}
	try{$article_id=kb_counter_category_id($article_id);$rating=kb_counter_category_id($rating);}catch(RuntimeException$error){kb_publication_fail('Not_Authorised');}
	if($rating>10){kb_publication_fail('Not_Authorised');}
	$address=kb_rating_address(isset($client_ip)?$client_ip:(isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:null));
	$owner=new PhpbbKbRatingDatabase($database,$article_id);$original=$GLOBALS['db'];
	try{
		$GLOBALS['db']=$owner;$owner->begin();$actor=$owner->actor();$article=$owner->article;$conditions=array();
		if($owner->settings['votes_check_ip']==='1'){$conditions[]="HEX(INET6_ATON(votes_ip)) IN ('".implode("','",$address['hex'])."')";}
		// All guests share the sentinel -1, not an account identity. The explicit
		// IP switch, rather than another guest's vote, governs their duplicates.
		if($owner->settings['votes_check_userid']==='1'&&(int)$actor['user_id']>0){$conditions[]='votes_userid='.(int)$actor['user_id'];}
		if($conditions&&$owner->rows('SELECT votes_file FROM '.KB_VOTES_TABLE.' WHERE votes_file='.$article_id.' AND ('.implode(' OR ',$conditions).') LIMIT 1')){$owner->commit();return array('duplicate'=>true,'article'=>$article);}
		$sum=(string)$article['article_rating'];$count=(string)$article['article_totalvotes'];
		if(preg_match('/^[0-9]{1,26}\.[0-9]{4}$/D',$sum)!==1||preg_match('/^[0-9]{1,20}$/D',$count)!==1||$count==='18446744073709551615'){kb_publication_fail('KB_rating_failed');}
		$owner->sql_query('INSERT INTO '.KB_VOTES_TABLE." (votes_ip,votes_userid,votes_file) VALUES('".$owner->sql_escape($address['address'])."',".(int)$actor['user_id'].','.$article_id.')');
		$owner->sql_query('UPDATE '.KB_ARTICLES_TABLE.' SET article_rating=article_rating+'.$rating.',article_totalvotes=article_totalvotes+1 WHERE article_id='.$article_id);
		if((int)$owner->sql_affectedrows()!==1){kb_publication_fail('KB_rating_failed');}
		$rows=$owner->rows('SELECT article_id,article_category_id,article_title,article_author_id,approved,article_rating,article_totalvotes,ROUND(article_rating/article_totalvotes,3) AS rating_average FROM '.KB_ARTICLES_TABLE.' WHERE article_id='.$article_id.' AND article_rating='.$sum.'+'.$rating.' AND article_totalvotes='.$count.'+1');
		if(count($rows)!==1){kb_publication_fail('KB_rating_failed');}$owner->commit();
		return array('duplicate'=>false,'article'=>$rows[0],'rating'=>$rating);
	}catch(PhpbbKbPublicationException$error){throw$error;}
	catch(Exception$error){kb_publication_fail('KB_rating_failed');}
	catch(Error$error){kb_publication_fail('KB_rating_failed');}
	finally{$GLOBALS['db']=$original;$owner->release();}
}
