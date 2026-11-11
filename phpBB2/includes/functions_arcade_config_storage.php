<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_submission.php';

function phpbb_arcade_config_fields($mode)
{
    $sets=array(
        ''=>array('bool'=>'use_gk_shop,use_gamelib,use_rewards_mod,use_point_system,use_allowance_system,use_cash_system',
            'int'=>'games_per_page,games_per_admin_page,games_posts_required,games_default_id,games_total_top,games_default_rate,games_image_width,games_image_height,games_cat_image_width,games_cat_image_height,games_rank_required,games_group_required,games_new_for',
            'path'=>'games_path,gamelib_path','asset'=>'games_default_img','text'=>'games_default_txt,games_cat_zero','cash'=>'default_cash','sort'=>'default_sort','order'=>'default_sort_order'),
        'switches'=>array('bool'=>'games_offline,games_no_guests,games_auto_size,games_guest_highscore,games_at_highscore,games_show_stats,games_mod_ban_users,games_rate,games_comments,games_new_games,games_show_played,games_show_all,games_show_fav,games_show_mhm,games_use_log,games_cheat_mode,warn_cheater,report_cheater,games_use_pms,games_tournament_mode,games_moderators_mode'),
        'messages'=>array('bool'=>'games_pm_new,games_pm_highscore,games_pm_at_highscore,games_pm_comment'));
    if(!is_string($mode)||!isset($sets[$mode])){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
    $out=array();foreach($sets[$mode]as$type=>$keys){foreach(explode(',',$keys)as$key){$out[$key]=$type;}}return $out;
}
function phpbb_arcade_config_input($request)
{
    global $mode_types;
    if(!is_array($request)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
    $mode=isset($request['new_mode'])?$request['new_mode']:(isset($request['mode'])?$request['mode']:'');$fields=phpbb_arcade_config_fields($mode);$values=array();
    foreach($request as$key=>$raw){
        if(!is_string($raw)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
        if(in_array($key,array('sid','submit','mode','new_mode'),true)){if(in_array($key,array('mode','new_mode'),true)&&$raw!==$mode){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}continue;}
        if(!isset($fields[$key])){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
        $value=phpbb_request_raw_value($raw);
        if(phpbb_arcade_text_limit($value,255)!==$value){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
        $type=$fields[$key];
        if($type==='bool'){if(!in_array($value,array('0','1'),true)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}}
        elseif($type==='int'){if(!preg_match('/^-?[0-9]{1,12}$/D',trim($value))){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}$value=(string)min(1000000000,max(0,(float)trim($value)));}
        elseif($type==='path'||$type==='asset'){
            $value=str_replace('\\','/',trim($value));
            if(($value!==''&&phpbb_arcade_local_asset($value)!==$value)||($type==='path'&&$value==='')||preg_match('/[\x00-\x1f\x7f?#]/',$value)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
            // Keep the historical disabled-library sentinel; do not rewrite
            // omitted controls, or an unused "0", as a different directory.
            if($type==='path'&&$value!=='0'){$value=rtrim($value,'/').'/';}
            if(phpbb_arcade_text_limit($value,255)!==$value){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}
        }elseif($type==='cash'){
            if($value!=='0'&&(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$value)||in_array(strtolower($value),phpbb_arcade_submission_core_user_fields(),true))){throw new PhpbbOwnedStorageFailure('arcade_config_currency');}
        }elseif($type==='sort'){if(!is_array($mode_types)||!in_array($value,$mode_types,true)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}}
        elseif($type==='order'){$value=strtoupper($value);if(!in_array($value,array('ASC','DESC'),true)){throw new PhpbbOwnedStorageFailure('arcade_config_invalid');}}
        $values[$key]=$value;
    }
    return array('mode'=>$mode,'values'=>$values);
}
class PhpbbArcadeConfigWriter extends PhpbbArcadeImportWriter
{
    protected $mode;
    function __construct($database,$mode){phpbb_arcade_config_fields($mode);$this->mode=$mode;parent::__construct($database);}
    protected function storage_tables(){return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,iNA,CONFIG_TABLE,iNA_LOG,GROUPS_TABLE,RANKS_TABLE);}
    protected function actor(){global $phpEx;return phpbb_acp_actor($this,'admin_arcade.'.$phpEx.($this->mode===''?'':'?mode='.$this->mode));}
    function save($values)
    {
        global $userdata;
        $actor=$this->actor();$sid=$this->escape($userdata['session_id']);
        foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id'])as$sql){$this->rows($sql.' LOCK IN SHARE MODE');}$actor=$this->actor();
        $current=array();foreach($this->rows('SELECT config_name,config_value FROM '.iNA.' ORDER BY config_name FOR UPDATE')as$row){$current[$row['config_name']]=$row['config_value'];}
        // Historical installers omitted this otherwise existing form setting.
        // Repair only this known default on an authorized General save, in the
        // same transaction as its validated value and audit entry. GETs and
        // saves of unrelated settings must not create configuration rows.
        $initialize_group=$this->mode===''&&isset($values['games_group_required'])&&!array_key_exists('games_group_required',$current);
        if($initialize_group){$current['games_group_required']='0';}
        foreach($values as$key=>$value){if(!array_key_exists($key,$current)){$this->storage_fail('Owned_storage_upgrade');}}
        $merged=array_replace($current,$values);
        foreach(array('games_group_required'=>array(GROUPS_TABLE,'group_id',' AND group_single_user=0'),'games_rank_required'=>array(RANKS_TABLE,'rank_id',' AND rank_special=0'))as$key=>$ref){if(isset($values[$key])&&(int)$values[$key]>0&&!$this->rows('SELECT '.$ref[1].' FROM '.$ref[0].' WHERE '.$ref[1].'='.(int)$values[$key].$ref[2].' LOCK IN SHARE MODE')){$this->storage_fail('arcade_config_invalid');}}
        $reward_keys=array('use_rewards_mod','use_point_system','use_allowance_system','use_cash_system','default_cash');
        if($this->mode===''&&array_intersect(array_keys($values),$reward_keys)){
            foreach($reward_keys as$key){if(!isset($merged[$key])){$this->storage_fail('Owned_storage_upgrade');}}
            $systems=(int)!empty($merged['use_point_system'])+(int)!empty($merged['use_cash_system'])+(int)!empty($merged['use_allowance_system']);
            if($systems>1){$this->storage_fail('admin_arcade_reward_error');}
            if($systems){
                $board=$this->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='default_reward_dbfield' LOCK IN SHARE MODE");
                $field=!empty($merged['use_point_system'])?'user_points':($board&&$board[0]['config_value']!==''?$board[0]['config_value']:$merged['default_cash']);
                $valid=is_string($field)&&preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$field)&&!in_array(strtolower($field),phpbb_arcade_submission_core_user_fields(),true);
                if(!empty($merged['use_rewards_mod'])){
                    if(!$valid){$this->storage_fail('arcade_config_currency');}
                    $meta=$this->rows("SELECT DATA_TYPE,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".USERS_TABLE."') AND HEX(COLUMN_NAME)=HEX('$field')");
                    if(count($meta)!==1||!in_array(strtolower($meta[0]['DATA_TYPE']),array('tinyint','smallint','mediumint','int','bigint','decimal','float','double'),true)||$meta[0]['COLUMN_KEY']==='PRI'||strpos($meta[0]['EXTRA'],'auto_increment')!==false){$this->storage_fail('arcade_config_currency');}
                }
                // This compatibility value follows the wallet actually used
                // by score/launch writers; a forum-wide currency has priority.
                if($valid){if(!isset($current['default_reward_dbfield'])){$this->storage_fail('Owned_storage_upgrade');}$values['default_reward_dbfield']=$field;$merged['default_reward_dbfield']=$field;}
            }
        }
        $reload=false;foreach(array('games_tournament_mode','games_moderators_mode','games_use_pms')as$key){if(isset($values[$key])&&$values[$key]!==$current[$key]){$reload=true;}}
        if($initialize_group){$this->query('INSERT INTO '.iNA." (config_name,config_value) VALUES('games_group_required','0')");}
        foreach($values as$key=>$value){$this->query('UPDATE '.iNA." SET config_value='".$this->escape($value)."' WHERE config_name='".$this->escape($key)."'");}
        if($values&&(!empty($current['games_use_log'])||!empty($merged['games_use_log']))){$message=$this->escape('Arcade settings mode='.($this->mode===''?'configuration':$this->mode).' fields='.implode(',',array_keys($values)));$this->query('INSERT INTO '.iNA_LOG.' (user_id,name,value,date) VALUES('.(int)$actor['user_id'].",'GAME','$message',".time().')');}
        $this->commit_attempted=true;$this->commit();return array('mode'=>$this->mode,'reload'=>$reload);
    }
}
function phpbb_arcade_config_save($database,$request)
{
    global $arcade,$phpbb_root_path;$input=phpbb_arcade_config_input($request);phpbb_attach_quota_post($request);
    if(!phpbb_request_source_is_same_origin()){throw new PhpbbOwnedStorageFailure('Session_invalid');}
    $writer=null;try{$writer=new PhpbbArcadeConfigWriter($database,$input['mode']);return $writer->save($input['values']);}
    finally{if($writer){try{if($writer->commit_attempted){try{$arcade->clear_cache('config',$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}finally{$writer->release();}}}
}
