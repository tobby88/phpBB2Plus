<?php
function ng_module($file,$config=array()){
 global$template,$board_config,$nt_app;
 $template=nt_template($file);$board_config=array_merge($board_config,array('server_name'=>'forum.example.invalid','server_port'=>443,'cookie_secure'=>1,'script_path'=>'','news_index_file'=>'news_index.php','news_base_url'=>''),$config);
 return new NewsModule($nt_app);
}
function ng_html($n,$block){$n->renderPagination();$t=$n->template;return array(isset($t->_tpldata[$block.'.'])?$t->_tpldata[$block.'.']:array(),nt_html($t));}
class NewsPageRows extends NewsCommentRows{
 var $size=10;var $seen=array();var $list_counts=0;var $post_reads=0;
 function setItemCount($size){if((int)$size>0){$this->size=min(100,(int)$size);}}function getItemCount(){return$this->size;}
 function fetchPosts($id,$start=0){$this->post_reads++;$rows=array_slice($this->rows,$start,$this->size);$this->seen=array();foreach($rows as$r){$this->seen[]=$r['post_id'];}return$rows;}
 function fetchPostsCount($id){return count($this->rows);}
 function fetchArticle($id){return$id===110?array($this->rows[0]):array();}
 function fetchArticlesCount($cat=0){$this->list_counts++;return count($this->rows);}
 function fetchArticles($sort=0,$cat=0,$start=0){return array_slice($this->rows,$start,$this->size);}
}
function ng_pure_suite(){
 global$db,$ng_cases,$ng_views,$board_config;
 $db=new NewsTextDatabase();
 foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){nt_context($locale,$logged);
  foreach(array(array(1,0,0,1,0,1),array(2,0,2,2,2,2),array(2,0,3,2,2,2),array(2,0,999999999,2,2,2),array(2,0,-1,2,0,2),array(2,0,array(2),2,0,2),array(2,0,'99999999999999999999',2,2,2),array(0,0,0,10,0,4),array(-1,0,0,10,0,4),array(101,0,0,100,0,4),array(2,3,0,3,0,3))as$v){
   $_GET=array('news_id'=>110,'start'=>$v[2]);$n=ng_module('news.tpl',array('news_item_num'=>$v[0]));$n->data=new NewsPageRows(nt_rows());$n->renderArticles(110,$v[1]);list($bound,$html)=ng_html($n,'comments');nt_check($n->page_size===$v[3]&&$n->page_start===$v[4]&&count($bound)===$v[5],'Actual module uses one bounded page size/start and reads only that page');nt_check($n->data->list_counts===0,'Single article avoids an unrelated article-list count');nt_check(($n->item_count>$n->page_size)===(strpos($html,'?news=article')!==false),'Pager appears exactly when another page exists');if($n->item_count>$n->page_size){nt_check(strpos($html,'topic_id=110')!==false&&strpos($html,'news_id=')===false,'Pager keeps the actual article ID for legacy aliases');}$ng_cases++;
  }
  foreach(array('news.tpl','portal_body.tpl')as$file){$_GET=array('news_id'=>110);$n=ng_module($file,array('news_item_num'=>2,'news_base_url'=>'https://news.example.invalid/archive/'));$n->data=new NewsPageRows(nt_rows());$n->render();$html=nt_html($n->template);nt_check(strpos($html,'https://news.example.invalid/archive/news_index.php?news=article&amp;topic_id=110&amp;start=2')!==false,'Actual dispatcher and XS template retain the validated index URL and alias');$ng_views++;
   $_GET=array('topic_id'=>999);$n=ng_module($file,array('news_item_num'=>2,'news_index_file'=>'../bad.php','news_base_url'=>'javascript:alert(1)'));$n->data=new NewsPageRows(nt_rows());$n->renderArticles(110);list($bound,$html)=ng_html($n,'comments');nt_check(strpos($html,'news_index.php?news=article&amp;topic_id=110&amp;start=2')!==false&&strpos($html,'bad.php')===false&&strpos($html,'javascript:')===false,'Pager uses the constructor fallback and actual reader argument');$ng_views++;
   $_GET=array('cat_id'=>2);$n=ng_module($file,array('news_item_num'=>2));$n->data=new NewsPageRows(nt_rows());$n->renderArticles();list($bound,$html)=ng_html($n,'articles');nt_check(count($bound)===2&&strpos($html,'cat_id=2&amp;start=2')!==false&&strpos($html,'?news=article&amp;topic_id=')===false,'Article list pager retains category and effective page size');$ng_views++;
   $_GET=array('topic_id'=>999);$n=ng_module($file,array('news_item_num'=>2));$n->data=new NewsPageRows(nt_rows());$n->render();$html=nt_html($n->template);nt_check(!isset($n->template->_tpldata['comments.'])&&!isset($n->template->_tpldata['pagination.'])&&$n->data->post_reads===0&&$n->item_count===0,'Missing parent cannot render orphan comments or navigation');$ng_views++;
  }
 }}
}
