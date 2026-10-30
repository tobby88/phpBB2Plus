<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_toplist.php';

function pafiledb_category_file_scope($model,$category)
{
    if(!(is_int($category)||is_string($category))||!preg_match('/^[1-9][0-9]*$/D',(string)$category)||(float)$category>2147483647||!isset($model->cat_rowset[(int)$category])){return array();}
    $scope=array();
    // A parent's file-view setting governs its own files, not its readable
    // children's files. Folder categories contribute descendants only.
    foreach($model->cat_rowset as$id=>$row){
        if((string)$row['cat_allow_file']!==(string)PA_CAT_ALLOW_FILE||!pafiledb_file_category_accessible($model,$id)){continue;}
        $cursor=(int)$id;while($cursor!==0){if($cursor===(int)$category){$scope[(int)$id]=(int)$id;break;}$parent=$model->cat_rowset[$cursor]['cat_parent'];$cursor=$parent===null?0:(int)$parent;}
    }
    ksort($scope,SORT_NUMERIC);return array_values($scope);
}

function pafiledb_category_file_summary_sql($scope,$cutoff)
{
    if(!is_array($scope)||!$scope||!is_string($cutoff)||!preg_match('/^-?[0-9]+$/D',$cutoff)){throw new InvalidArgumentException('Invalid category file summary');}
    foreach($scope as$id){if(!is_int($id)||$id<1||$id>2147483647){throw new InvalidArgumentException('Invalid category identity');}}
    $ids=implode(',',$scope);
    // One statement gives counts and the latest visible file the same InnoDB
    // read view. No stale persistent cache or write-on-GET is involved.
    return 'SELECT totals.total_files,COALESCE(totals.new_files,0) AS new_files,latest.file_id,latest.file_name,latest.file_time,latest.file_catid'
        .' FROM (SELECT COUNT(*) AS total_files,SUM(CASE WHEN file_time>'.$cutoff.' THEN 1 ELSE 0 END) AS new_files FROM '.PA_FILES_TABLE.' WHERE file_approved=1 AND file_catid IN('.$ids.')) totals'
        .' LEFT JOIN '.PA_FILES_TABLE.' latest ON latest.file_id=(SELECT newest.file_id FROM '.PA_FILES_TABLE.' newest WHERE newest.file_approved=1 AND newest.file_catid IN('.$ids.') ORDER BY newest.file_time DESC,newest.file_id DESC LIMIT 1)';
}

function pafiledb_category_file_label($value)
{
    $raw=is_string($value)?$value:'';$short=$raw;
    if(preg_match('/^.{0,20}/us',$raw,$match)===1){$short=$match[0];if(strlen($short)<strlen($raw)){$short.='...';}}
    return array('title'=>htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'short'=>htmlspecialchars($short,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
}
