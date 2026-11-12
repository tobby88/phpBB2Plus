<?php
// Exercise complete shipped controllers. Only forum bootstrap, event providers
// and output transports are replaced; parameter parsing and navigation run as-is.
if (PHP_SAPI !== 'cli') { exit(2); }
foreach (array('IN_PHPBB'=>true,'PAGE_INDEX'=>0,'POST_FORUM_URL'=>'f','POST_CAT_URL'=>'c') as $key=>$value) {
    if (!defined($key)) { define($key,$value); }
}
$cn_root=dirname(dirname(__DIR__)).'/phpBB2/';
$cn_functions=file_get_contents($cn_root.'includes/functions_calendar.php');
foreach (array('calendar_normalize_forum_filter','calendar_month_date') as $name) {
    if (preg_match('/^function '.$name.'\(.*?^\}/ms',$cn_functions,$part)===1) { eval($part[0]); }
}
function session_pagestart($ip,$page) { return array('session_logged_in'=>true,'user_id'=>7); }
function init_userprefs($user) {}
function append_sid($url) { return $url; }
function make_jumpbox($url) {}
function calendar_get_tree_option($selected='') { return '<option value="'.htmlspecialchars($selected,ENT_QUOTES,'UTF-8').'" selected="selected">Fixture forum</option>'; }
function display_calendar($name,$type,$date,$filter) { $GLOBALS['cn_month_input']=array($date,$filter); }
function get_birthday(&$events,&$number,$first,$last,$limit=false,$offset=0,$maximum=-1) {
    $number=isset($GLOBALS['cn_fixture']['birthdays'])?$GLOBALS['cn_fixture']['birthdays']:0;
    $GLOBALS['cn_windows'][]=array('birthday',$first,$last,$limit,$offset,$maximum);
    cn_collect($events,'b',$number,$first,$limit,$offset,$maximum);
}
function get_event_topics(&$events,&$number,$first,$last,$limit=false,$offset=0,$maximum=-1,$filter='') {
    $number=isset($GLOBALS['cn_fixture']['topics'])?$GLOBALS['cn_fixture']['topics']:0;
    $GLOBALS['cn_windows'][]=array('topic',$first,$last,$limit,$offset,$maximum,$filter);
    cn_collect($events,'t',$number,$first,$limit,$offset,$maximum);
}
function cn_collect(&$events,$kind,$number,$first,$limit,$offset,$maximum) {
    $end=$limit?min($number,$offset+$maximum):$number;$offset=$limit?$offset:0;
    for($index=$offset;$index<$end;$index++) {
        $events[]=array('event_id'=>$kind.$index,'event_title'=>'Fixture '.$kind.$index,
            'event_replies'=>0,'event_type'=>0,'event_vote'=>0,'event_status'=>0,'event_moved_id'=>0,
            'event_last_time'=>$first,'event_author_id'=>7,'event_author'=>'Fixture author','event_time'=>$first,
            'event_last_author_id'=>7,'event_last_author'=>'Fixture author','event_last_id'=>1,'event_views'=>0,
            'event_forum_id'=>6,'event_forum_name'=>'Fixture forum','event_calendar_time'=>$first,
            'event_calendar_duration'=>0,'event_icon'=>0);
    }
}
function get_calendar_title_date($first,$duration) { return 'fixture period'; }
function topic_list($name,$box,$rows,$title,$split,$navigation,$footer) { $GLOBALS['cn_display_rows']=$rows;$GLOBALS['template']->assign_vars(array($name=>$footer)); }
function generate_pagination($url,$total,$perPage,$offset) { $GLOBALS['cn_paging']=$url;return ''; }
class CalendarNavigationTemplate {
    public $vars=array();public $blocks=array();public $assignments=array();
    function set_filenames($files) {}
    function assign_vars($vars) { $this->vars=array_merge($this->vars,$vars); }
    function assign_block_vars($name,$vars) { $this->blocks[$name][]=$vars;$this->assignments[]=array($name,$vars); }
    function pparse($name) {}
}
function cn_run($page,$get,$post,$server='UTC',$week=1,$locale='english',$fixture=array()) {
    global $cn_root,$board_config,$lang,$template,$userdata,$images,$phpEx,$user_ip;
    if (!in_array($page,array('calendar','calendar_scheduler'),true)) { throw new RuntimeException('Known calendar controller required'); }
    date_default_timezone_set($server);$_GET=$get;$_POST=$post;
    $board_config=array('default_lang'=>$locale,'topics_per_page'=>3,'calendar_week_start'=>$week,'calendar_forum'=>0);
    $lang=array();$lang_extend_admin=false;
    require $cn_root.'language/lang_'.$locale.'/lang_main.php';
    require $cn_root.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';
    $images=array('icon_calendar'=>'fixture.gif');$template=new CalendarNavigationTemplate();$phpEx='php';$user_ip='7f000001';
    $GLOBALS['cn_windows']=array();$GLOBALS['cn_paging']='';$GLOBALS['cn_month_input']=null;$GLOBALS['cn_fixture']=$fixture;$GLOBALS['cn_display_rows']=array();
    $source=file_get_contents($cn_root.$page.'.php');
    $source=preg_replace('/^include\s*\([^\r\n]+\);\r?\n/m','',$source,-1,$removed);
    if ($removed!==($page==='calendar'?5:6)) { throw new RuntimeException('Exact controller bootstrap/output include inventory required'); }
    $source=preg_replace("/^define\('IN_(?:PHPBB|CALENDAR)', true\);\r?\n/m",'',$source,-1,$removed);
    if ($removed!==($page==='calendar'?2:1)) { throw new RuntimeException('Exact controller entry constants required'); }
    eval('?>'.$source);
    return array('vars'=>$template->vars,'blocks'=>$template->blocks,'assignments'=>$template->assignments,'date'=>$page==='calendar'?$start_date:$date,
        'windows'=>$GLOBALS['cn_windows'],'paging'=>$GLOBALS['cn_paging'],'month_input'=>$GLOBALS['cn_month_input'],'display_rows'=>$GLOBALS['cn_display_rows']);
}
function cn_url_date($url) {
    $parts=parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'));$query=array();
    parse_str(isset($parts['query'])?$parts['query']:'',$query);
    return isset($query['d'])?(int)$query['d']:null;
}
function cn_grid_days($result) {
    $days=array();foreach ($result['blocks']['row.cell'] as $row) {
        $label=trim(html_entity_decode(strip_tags((string)$row['DAY']),ENT_QUOTES,'UTF-8'));
        if (ctype_digit($label)) { $days[]=(int)$label; }
    }return $days;
}
function cn_render($result) {
    global $cn_root,$board_config,$theme,$phpbb_root_path,$phpEx,$lang;
    require_once $cn_root.'includes/php_compat.php';require_once $cn_root.'includes/template.php';
    if (!function_exists('phpbb_realpath')) {
        if (preg_match('/^function phpbb_realpath\(.*?^\}/ms',file_get_contents($cn_root.'includes/functions.php'),$part)!==1) { throw new RuntimeException('Actual template path helper required'); }eval($part[0]);
    }
    $phpbb_root_path=$cn_root;$board_config+=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23');
    $theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2');
    $out=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$out->vars=&$out->_tpldata['.'][0];
    $out->load_config($cn_root.'templates/fisubsilversh',false);$out->set_filenames(array('body'=>'calendar_scheduler_body.tpl'));
    $out->assign_vars($result['vars']);foreach ($result['assignments'] as $row) { $out->assign_block_vars($row[0],$row[1]); }
    ob_start();try { $out->pparse('body'); } finally { $html=ob_get_clean(); }return $html;
}
function cn_form($html) {
    if (preg_match('/<form\b[^>]*name="_calendar_scheduler"[^>]*action="([^"]*)"[^>]*>(.*?)<\/form>/s',$html,$form)!==1) { throw new RuntimeException('Actual scheduler form required'); }
    $post=array();preg_match_all('/<input\b[^>]*name="([^"]*)"[^>]*value="([^"]*)"[^>]*>/s',$form[2],$inputs,PREG_SET_ORDER);
    foreach($inputs as$input){$post[$input[1]]=html_entity_decode($input[2],ENT_QUOTES,'UTF-8');}
    preg_match_all('/<select\b[^>]*name="([^"]*)"[^>]*>(.*?)<\/select>/s',$form[2],$selects,PREG_SET_ORDER);
    foreach($selects as$select){if(preg_match('/<option\b[^>]*value="([^"]*)"[^>]*selected="selected"[^>]*>/s',$select[2],$option)!==1){throw new RuntimeException('Actual selected calendar option required');}$post[$select[1]]=html_entity_decode($option[1],ENT_QUOTES,'UTF-8');}
    return array('action'=>html_entity_decode($form[1],ENT_QUOTES,'UTF-8'),'post'=>$post);
}
