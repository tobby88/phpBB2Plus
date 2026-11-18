<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';

function phpbb_icon_config_id($value)
{
    if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]{1,5}$/D', (string)$value) || (int)$value > 65535) { return false; }
    return (int)$value;
}
function phpbb_icon_config_valid($config)
{
    if (!is_array($config) || !isset($config['icons'], $config['special']) || !is_array($config['icons']) || !is_array($config['special'])
        || count($config['icons']) > 256 || count($config['special']) > 64) { return false; }
    $ids = array();
    foreach ($config['icons'] as $icon) {
        if (!is_array($icon) || !isset($icon['ind'], $icon['img'], $icon['alt'], $icon['auth']) || !is_string($icon['img']) || !is_string($icon['alt'])) { return false; }
        $id = phpbb_icon_config_id($icon['ind']); $auth = phpbb_icon_config_id($icon['auth']);
        // Stable phpBB AUTH_ALL/REG/MOD/ADMIN protocol values. Reading the data
        // does not depend on constants having been installed by a web bootstrap.
        if ($id === false || isset($ids[$id]) || !in_array($auth, array(0,1,3,5), true)
            || !preg_match('#^(?:|[A-Za-z0-9_][A-Za-z0-9_./-]{0,254})$#D', $icon['img']) || strpos($icon['img'], '..') !== false
            || !preg_match('/^[A-Za-z0-9_]{1,100}$/D', $icon['alt'])) { return false; }
        $ids[$id] = true;
    }
    if (!isset($ids[0])) { return false; }
    foreach ($config['special'] as $key=>$special) {
        if (!is_string($key) || !preg_match('/^[A-Z_]{1,64}$/D', $key) || !is_array($special) || !isset($special['lang_key'])
            || !array_key_exists('icon', $special) || !is_string($special['lang_key']) || !preg_match('/^[A-Za-z0-9_]{1,100}$/D', $special['lang_key'])) { return false; }
        $id = phpbb_icon_config_id($special['icon']); if ($id === false || !isset($ids[$id])) { return false; }
    }
    foreach (array('POST_ATTACHMENT','POST_PICTURE','POST_CALENDAR','POST_BIRTHDAY','POST_GLOBAL_ANNOUNCE','POST_ANNOUNCE','POST_STICKY','POST_NORMAL') as $key) {
        if (!isset($config['special'][$key])) { return false; }
    }
    return true;
}
function phpbb_normalize_icon_config($icons, $special)
{
    $config = array('icons'=>array(), 'special'=>array());
    if (!is_array($icons) || !is_array($special)) { return false; }
    foreach ($icons as $icon) {
        if (!is_array($icon) || !isset($icon['ind'], $icon['img'], $icon['alt'], $icon['auth'])) { return false; }
        $id=phpbb_icon_config_id($icon['ind']); $auth=phpbb_icon_config_id($icon['auth']);
        if ($id===false || $auth===false) { return false; }
        $config['icons'][]=array('ind'=>$id,'img'=>$icon['img'],'alt'=>$icon['alt'],'auth'=>$auth);
    }
    foreach ($special as $key=>$item) {
        if (!is_array($item) || !isset($item['lang_key']) || !array_key_exists('icon',$item)) { return false; }
        // Older editors used an empty string for the ordinary no-icon default.
        $id=phpbb_icon_config_id($item['icon']===''?0:$item['icon']); if ($id===false) { return false; }
        $config['special'][$key]=array('lang_key'=>$item['lang_key'],'icon'=>$id);
    }
    return phpbb_icon_config_valid($config)?$config:false;
}
function phpbb_icon_config_decode($value)
{
    if (!is_string($value) || strlen($value)>4194304 || preg_match('//u',$value)!==1) { return false; }
    $config=phpbb_safe_unserialize_array($value);
    if (!is_array($config) || !isset($config['icons'],$config['special']) || !is_array($config['icons']) || !is_array($config['special']) || count($config['icons'])>256 || count($config['special'])>64) { return false; }
    return phpbb_normalize_icon_config($config['icons'],$config['special']);
}
function phpbb_icon_defaults($root)
{
    $icon_default_icons=$icon_default_special=array();
    require rtrim($root,'/\\').'/includes/icon_defaults.php';
    return phpbb_normalize_icon_config($icon_default_icons,$icon_default_special);
}
function phpbb_icon_legacy_source($root)
{
    $root=realpath($root); if ($root===false || !is_dir($root)) { throw new RuntimeException('Existing forum root required for icon import'); }
    $file=$root.'/data/icons.dat'; clearstatcache(true,$file);
    if (file_exists($file) || is_link($file)) {
        if (is_link($file) || !is_file($file) || realpath(dirname($file))!==realpath($root.'/data') || filesize($file)>4194304) { throw new RuntimeException('Unsafe existing icon data file'); }
        $bytes=file_get_contents($file); $config=phpbb_icon_config_decode($bytes);
        if ($config===false) { throw new RuntimeException('Existing icon data is invalid; retain it and inspect before import'); }
        return array('kind'=>'serialized','path'=>$file,'sha256'=>hash('sha256',$bytes),'config'=>$config);
    }
    $legacy=$root.'/includes/def_icons.php'; clearstatcache(true,$legacy);
    if (file_exists($legacy) || is_link($legacy)) {
        if (is_link($legacy) || !is_file($legacy) || filesize($legacy)>65536) { throw new RuntimeException('Unsafe legacy icon definition'); }
        $before=hash_file('sha256',$legacy); $icones=$icon_defined_special=array(); include $legacy;
        $config=phpbb_normalize_icon_config($icones,$icon_defined_special);
        if ($config===false || hash_file('sha256',$legacy)!==$before) { throw new RuntimeException('Legacy icon definition is invalid or changed'); }
        return array('kind'=>'legacy-php','path'=>$legacy,'sha256'=>$before,'config'=>$config);
    }
    $config=phpbb_icon_defaults($root); if ($config===false) { throw new RuntimeException('Bundled icon defaults are invalid'); }
    return array('kind'=>'defaults','path'=>null,'sha256'=>null,'config'=>$config);
}
function phpbb_icon_storage_shape($columns,$indexes)
{
    $expected=array('config_id'=>array('tinyint unsigned','NO',null),'schema_version'=>array('smallint unsigned','NO','1'),'config_value'=>array('mediumtext','NO',null));
    if (count($columns)!==3 || count($indexes)!==1) { return false; }
    foreach ($columns as $column) {
        if (!isset($expected[$column['Field']]) || $column['Extra']!=='') { return false; }
        $type=preg_replace('/^(tinyint|smallint)\([0-9]+\)/','$1',strtolower($column['Type'])); $spec=$expected[$column['Field']];
        if ($type!==$spec[0] || $column['Null']!==$spec[1] || ($column['Default']===null?null:(string)$column['Default'])!==$spec[2]) { return false; }
        if ($column['Field']==='config_value' && $column['Collation']!=='utf8mb4_unicode_ci') { return false; }
    }
    $index=$indexes[0]; return $index['Key_name']==='PRIMARY' && (string)$index['Non_unique']==='0' && (string)$index['Seq_in_index']==='1' && $index['Column_name']==='config_id' && $index['Sub_part']===null;
}
function phpbb_icon_reference_type($type)
{
    $type=preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/','$1',strtolower($type));
    return in_array($type,array('smallint unsigned','mediumint','mediumint unsigned','int','int unsigned','bigint','bigint unsigned'),true);
}
function phpbb_icon_label($key)
{
    global $lang;
    $value=isset($lang[$key])&&is_scalar($lang[$key])?(string)$lang[$key]:(string)$key;
    return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function phpbb_icon_config_read($database,$root)
{
    if (defined('POST_ICON_CONFIG_TABLE') && is_object($database) && is_callable(array($database,'sql_query'))) {
        try {
        $result=$database->sql_query('SELECT config_id,schema_version,config_value FROM '.POST_ICON_CONFIG_TABLE.' ORDER BY config_id LIMIT 2');
        if ($result) {
            try { $rows=$database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); }
            if (!is_array($rows) || count($rows)!==1 || (string)$rows[0]['config_id']!=='1' || (string)$rows[0]['schema_version']!=='1') { return false; }
            return phpbb_icon_config_decode($rows[0]['config_value']);
        }
        $error=$database->sql_error();
        // A not-yet-updated installation can still display its existing icons.
        // Any other SQL failure or corrupt authoritative row is never replaced.
        if (!is_array($error) || !isset($error['code']) || (string)$error['code']!=='1146') { return false; }
        } catch (Exception $error) { return false; }
        catch (Error $error) { return false; }
    }
    try { $source=phpbb_icon_legacy_source($root); return $source['config']; }
    catch (Exception $error) { return false; }
    catch (Error $error) { return false; }
}
function phpbb_load_icon_config($refresh=false)
{
    global $db,$phpbb_root_path,$icones,$icon_defined_special;
    static $context=null,$attempted=false,$loaded=false;
    $root=isset($phpbb_root_path)?$phpbb_root_path:dirname(__DIR__);
    $key=(is_object($db)?spl_object_hash($db):'no-database')."\0".$root."\0".(defined('POST_ICON_CONFIG_TABLE')?POST_ICON_CONFIG_TABLE:'legacy');
    if (!$refresh && $context===$key && $attempted && is_array($icones) && is_array($icon_defined_special)) { return $loaded; }
    $context=$key;$attempted=true;$config=phpbb_icon_config_read($db,$root);$loaded=$config!==false;
    if (!$loaded) {
        $config=phpbb_icon_defaults(dirname(__DIR__));$config['icons']=array(array('ind'=>0,'img'=>'','alt'=>'icon_none','auth'=>0));
        foreach ($config['special'] as &$item) { $item['icon']=0; }unset($item);
    }
    $icones=$config['icons'];$icon_defined_special=$config['special'];return $loaded;
}

function phpbb_icon_transition($config,$operation,$request,$labels)
{
    if (!phpbb_icon_config_valid($config) || !is_array($request) || !in_array($operation,array('save','delete','up','down'),true)) { throw new InvalidArgumentException('Icons_invalid'); }
    $map=array();foreach ($config['icons'] as $i=>$row) { $map[$row['ind']]=$i; }
    $raw=isset($request['icon'])?$request['icon']:'-1';$new=$operation==='save'&&$raw==='-1';$id=$new?-1:phpbb_icon_config_id($raw);
    if (!$new && ($id===false || !isset($map[$id]))) { throw new InvalidArgumentException('Icons_selection_changed'); }
    $replacement=null;
    if ($operation==='delete') {
        if (empty($request['confirm']) || $id===0) { throw new InvalidArgumentException($id===0?'Icons_error_del_0':'Icons_invalid'); }
        $replacement=array_key_exists('replace_icon',$request)?phpbb_icon_config_id($request['replace_icon']):0;
        if ($replacement===false || $replacement===$id || !isset($map[$replacement])) { throw new InvalidArgumentException('Icons_selection_changed'); }
        array_splice($config['icons'],$map[$id],1);
        foreach ($config['special'] as &$item) { if ((int)$item['icon']===$id) { $item['icon']=$replacement; } }unset($item);
    } elseif ($operation==='up' || $operation==='down') {
        $position=$map[$id];$next=$position+($operation==='up'?-1:1);
        if ($next>=0&&$next<count($config['icons'])) { $swap=$config['icons'][$next];$config['icons'][$next]=$config['icons'][$position];$config['icons'][$position]=$swap; }
    } else {
        if (!isset($request['icon_title'],$request['icon_url'],$request['icon_auth']) || !is_string($request['icon_title']) || !is_string($request['icon_url'])) { throw new InvalidArgumentException('Icons_invalid'); }
        $title=trim(phpbb_request_raw_value($request['icon_title']));$url=trim(phpbb_request_raw_value($request['icon_url']));$auth=phpbb_icon_config_id($request['icon_auth']);
        if ($new) { if (count($config['icons'])>=256) { throw new InvalidArgumentException('Icons_limit'); }$id=max(array_keys($map))+1;if($id>65535){throw new InvalidArgumentException('Icons_limit');}$map[$id]=count($config['icons']); }
        $selected=isset($request['ids'])?$request['ids']:array();if(!is_array($selected)||count($selected)>64){throw new InvalidArgumentException('Icons_invalid');}
        $set=array();foreach($selected as$key){if(!is_string($key)||!isset($config['special'][$key])||isset($set[$key])){throw new InvalidArgumentException('Icons_invalid');}$set[$key]=true;}
        $config['icons'][$map[$id]]=array('ind'=>$id,'img'=>$url,'alt'=>$title,'auth'=>$auth);
        foreach($config['special']as$key=>&$item){
            if(!isset($labels[$item['lang_key']])||!is_scalar($labels[$item['lang_key']])){if(isset($set[$key])){throw new InvalidArgumentException('Icons_invalid');}continue;}
            if(isset($set[$key])){$item['icon']=$id;}elseif((int)$item['icon']===$id){$item['icon']=0;}
        }unset($item);
    }
    $config=phpbb_normalize_icon_config($config['icons'],$config['special']);if($config===false){throw new InvalidArgumentException('Icons_invalid');}
    return array('config'=>$config,'removed'=>$operation==='delete'?$id:null,'replacement'=>$replacement);
}
