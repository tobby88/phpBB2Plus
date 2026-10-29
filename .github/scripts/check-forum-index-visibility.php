<?php
define('IN_PHPBB',true);define('POST_CAT_URL','c');define('POST_FORUM_URL','f');define('FORUM_LOCKED',1);
require dirname(dirname(__DIR__)).'/phpBB2/includes/functions_categories_hierarchy.php';
class ForumIndexVisibilityTemplate
{
    var $blocks=array();
    function assign_block_vars($block,$values){$this->blocks[]=array($block,$values);}
}
function append_sid($url){return $url;}
function index_visibility_check($condition,$message){if(!$condition){throw new RuntimeException($message);}}
function index_visibility_tree($visible)
{
    return array('keys'=>array('c4'=>0,'c5'=>1),'type'=>array('c','c'),'id'=>array(4,5),'main'=>array('Root','Root'),
        'data'=>array(array('cat_title'=>'First category','cat_desc'=>'First description'),array('cat_title'=>'Second category','cat_desc'=>'Second description')),
        'sub'=>array('Root'=>array('c4','c5'),'c4'=>array(),'c5'=>array()),
        'auth'=>array('c4'=>array('auth_view'=>$visible),'c5'=>array('auth_view'=>$visible)));
}
$phpEx='php';$images=array();$lang=array('Forum'=>'Forums');$cases=0;
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
try
{
    foreach(array(0,1,2)as$subforum){foreach(array(0,1)as$split){foreach(array('c4','c5','c999')as$cur){
        $board_config=array('sub_forum'=>$subforum,'split_cat'=>$split);$tree=index_visibility_tree(false);$template=new ForumIndexVisibilityTemplate();$moderators=array();$keys=array();
        index_visibility_check(build_index($cur,(bool)$split,$moderators,-1,-1,$keys)===false,'Inaccessible/missing category must not be displayed');
        index_visibility_check($template->blocks===array(),'Rejected category must not emit partial table rows');$cases++;
    }}}
    foreach(array(0,1)as$split){
        $board_config=array('sub_forum'=>0,'split_cat'=>$split);$tree=index_visibility_tree(true);$template=new ForumIndexVisibilityTemplate();$moderators=array();$keys=array();
        index_visibility_check(build_index('Root',(bool)$split,$moderators,-1,-1,$keys)===true,'Visible categories still render through real recursion');
        $titles=array();$heads=0;$ends=0;foreach($template->blocks as$block){if($block[0]==='catrow.cathead'){$titles[]=$block[1]['CAT_TITLE'];}if($block[0]==='catrow.tablehead'){$heads++;}if($block[0]==='catrow.tablefoot'){$ends++;}}
        index_visibility_check($titles===array('First category','Second category'),'Visible sibling categories retain their order and names');
        index_visibility_check($heads===($split?2:1)&&$ends===$heads,'Split and combined index tables remain balanced');$cases++;
        $tree=index_visibility_tree(false);$template=new ForumIndexVisibilityTemplate();$keys=array();
        index_visibility_check(build_index('Root',(bool)$split,$moderators,-1,-1,$keys)===false,'A valid root with no visible descendants remains empty without warnings');$cases++;
    }
    $board_config=array('sub_forum'=>0,'split_cat'=>0);$tree=index_visibility_tree(true);$template=new ForumIndexVisibilityTemplate();$moderators=array();$keys=array('keys'=>array('c4'=>7),'level'=>array());
    index_visibility_check(build_index('c4',false,$moderators,0,0,$keys)===false&&$template->blocks===array(),'Malformed recursive level map must fail before any rendering');$cases++;
    $images=array('forum_new'=>'new.gif','forum'=>'forum.gif','forum_locked'=>'locked.gif');
    $lang+=array('New_posts'=>'New posts','No_new_posts'=>'No new posts','Forum_locked'=>'Locked','No_Posts'=>'No posts');
    foreach(array(1,2)as$subforum){foreach(array(false,true)as$with_counts){
        $board_config=array('sub_forum'=>$subforum,'split_cat'=>0);$tree=index_visibility_tree(true);
        if($with_counts){$tree['data'][0]['tree.forum_posts']=13;$tree['data'][0]['tree.forum_topics']=7;}
        $template=new ForumIndexVisibilityTemplate();$keys=array('keys'=>array('c4'=>0),'level'=>array(0));
        index_visibility_check(build_index('c4',false,$moderators,1,0,$keys)===true,'Collapsed empty category still renders');
        $rows=array();foreach($template->blocks as$block){if($block[0]==='catrow.forumrow'){$rows[]=$block[1];}}
        index_visibility_check(count($rows)===1&&$rows[0]['POSTS']===($with_counts?13:0)&&$rows[0]['TOPICS']===($with_counts?7:0),'Collapsed category preserves authorized totals or displays zero without warnings');$cases++;
    }}
    foreach(array(false,true)as$visible){foreach(array('posts','topics')as$present){
        $tree=array('keys'=>array('c4'=>0,'f1'=>1),'type'=>array('c','f'),'id'=>array(4,1),'main'=>array('Root','c4'),
            'data'=>array(array('tree.forum_'.$present=>0),array('forum_posts'=>13,'forum_topics'=>7,'tree.forum_'.$present=>0)),
            'auth'=>array('f1'=>array('auth_view'=>$visible,'auth_read'=>false)));
        set_tree_user_auth();
        foreach($tree['data']as$data){index_visibility_check($data['tree.forum_posts']===($visible?13:0)&&$data['tree.forum_topics']===($visible?7:0),'Partial count caches aggregate both totals once and omit inaccessible forum counts');}$cases++;
    }}
    echo 'Forum index visibility: '.$cases.' real hierarchy rendering cases PASS; rejected categories, empty roots, sibling order and split/combined tables; no database.',"\n";
}
finally{restore_error_handler();}
