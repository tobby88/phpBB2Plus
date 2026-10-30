<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit(2); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB',true); }

function plus_pa_recovery_installation()
{
    $project=dirname(__DIR__);$candidate=is_dir($project.'/phpBB2')?$project.'/phpBB2':$project;
    if (is_link($candidate)||($root=realpath($candidate))===false||!is_file($root.'/pafiledb/includes/functions_file_journal.php')) { throw new RuntimeException('Recovery must belong to the configured forum installation'); }
    return $root;
}

function plus_pa_recovery_options($arguments)
{
    $options=array('apply'=>false,'backup-confirmed'=>false,'help'=>false,'items'=>50,'after'=>'');$seen=array();
    foreach ($arguments as $argument) {
        if (!is_string($argument)||!preg_match('/^--([a-z-]+)(?:=(.*))?$/D',$argument,$match)||!array_key_exists($match[1],$options)||isset($seen[$match[1]])) { throw new InvalidArgumentException('Unknown, duplicate or positional recovery argument'); }
        $key=$match[1];$seen[$key]=true;
        if (in_array($key,array('apply','backup-confirmed','help'),true)) {
            if (isset($match[2])) { throw new InvalidArgumentException('Recovery flags take no value'); }$options[$key]=true;
        } elseif ($key==='items') {
            if (!isset($match[2])||!preg_match('/^[1-9][0-9]{0,2}$/D',$match[2])||(int)$match[2]>500) { throw new InvalidArgumentException('Recovery page must contain 1..500 items'); }$options[$key]=(int)$match[2];
        } else {
            if (!isset($match[2])||!preg_match('/^[a-f0-9]{32}:(0|[1-9][0-9]{0,6})$/D',$match[2],$cursor)||(int)$cursor[1]>1000000) { throw new InvalidArgumentException('Invalid recovery cursor'); }$options[$key]=$match[2];
        }
    }
    if ($options['apply']&&!$options['backup-confirmed']) { throw new InvalidArgumentException('Applying recovery requires verified database and download-file backups (--backup-confirmed)'); }
    return $options;
}

class PlusPaFileRecovery
{
    private $source;
    private $prefix;
    private $root;
    function __construct($source,$prefix,$root)
    {
        if (!is_object($source)||!method_exists($source,'sql_dedicated_connection')||!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)||!is_string($root)||is_link($root)||realpath($root)!==$root) { throw new InvalidArgumentException('Invalid trusted download recovery context'); }
        $code=plus_pa_recovery_installation();require_once $code.'/pafiledb/includes/functions_file_coordinator.php';require_once $code.'/pafiledb/includes/functions_file_journal.php';
        $this->source=$source;$this->prefix=$prefix;$this->root=$root;
    }
    private function rows($storage,$sql)
    {
        $r=$storage->sql_query($sql);try { return $storage->sql_fetchrowset($r); } finally { $storage->sql_freeresult($r); }
    }
    function run($options)
    {
        // Internal callers must supply the same typed options as the CLI parser.
        if (!is_array($options)||count($options)!==5||array_diff(array('apply','backup-confirmed','help','items','after'),array_keys($options))||!is_bool($options['apply'])||!is_bool($options['backup-confirmed'])||!is_bool($options['help'])||($options['apply']&&!$options['backup-confirmed'])) { throw new InvalidArgumentException('Invalid recovery confirmation'); }
        $sql=PaFileDbFileJournal::pending_query($this->prefix,$options['items'],$options['after']);$storage=null;
        try {
            $storage=new PaFileDbFileCoordinator($this->source,$this->prefix);$storage->begin();
            $report=array('applied'=>$options['apply'],'selected'=>0,'complete'=>0,'pending'=>0,'next_after'=>$options['after'],'page_full'=>false);
            if (!$options['apply']) {
                $rows=$this->rows($storage,$sql);$report['selected']=count($rows);
                if ($rows) { $last=$rows[count($rows)-1];$report['next_after']=$last['operation_id'].':'.$last['item_index']; }
            } else {
                $settings=array();foreach($this->rows($storage,'SELECT config_name,config_value FROM '.$this->prefix.'pa_config ORDER BY config_name FOR UPDATE')as$row){$settings[$row['config_name']]=$row['config_value'];}
                foreach(array('upload_dir','screenshots_dir')as$key){if(!isset($settings[$key])||!is_string($settings[$key])){throw new RuntimeException('Missing configured download directory');}}
                $origin=array();foreach($this->rows($storage,"SELECT config_name,config_value FROM ".$this->prefix."config WHERE config_name IN ('server_name','server_port','script_path','cookie_secure') ORDER BY config_name FOR UPDATE")as$row){$origin[$row['config_name']]=$row['config_value'];}
                if (count($origin)!==4||!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])$/iD',$origin['server_name'])||!preg_match('/^[0-9]{1,5}$/D',$origin['server_port'])||(int)$origin['server_port']>65535||!in_array($origin['cookie_secure'],array('0','1'),true)||preg_match('/[^a-z0-9._~!$&()*+,;=:@\/%-]/i',$origin['script_path'])){throw new RuntimeException('Invalid configured download origin');}
                $port=(int)$origin['server_port'];$url=($origin['cookie_secure']==='1'?'https':'http').'://'.$origin['server_name'].($port&&!in_array($port,array(80,443),true)?':'.$port:'').'/'.trim($origin['script_path'],'/');
                $journal=new PaFileDbFileJournal($storage,$this->prefix,-1,array('download'=>$settings['upload_dir'],'screenshot'=>$settings['screenshots_dir']),$url,$this->root);
                $result=$journal->resolve_pending($options['items'],$options['after']);
                foreach(array('selected','complete','pending','next_after')as$key){$report[$key]=$result[$key];}
                if($result['selected']){$storage->commit();}
            }
            $report['page_full']=$report['selected']===$options['items'];return $report;
        } finally { if($storage){$storage->release();} }
    }
}
