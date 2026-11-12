<?php
// Actual NewsModule, post parser and shipped XS templates; no SQL or user data.
require __DIR__.'/news-tombstone-fixture.php';
$cases=$views=0;$db=new NewsTextDatabase();
try{
 foreach(array('english','german')as$locale){
  nt_context($locale,true);$template=nt_template('news.tpl');$n=nt_module($template);
  $b='[b:abc1234567]';$bc='[/b:abc1234567]';$code='[code:abc1234567]';$cc='[/code:abc1234567]';
  $vectors=array(
   array('Aä😀Z',2,'Aä',true),array('Aä😀Z',3,'Aä😀',true),array('Ä😀',2,'Ä😀',false),array('abcdef',3,'abc',true),array('abc',3,'abc',false),array('',3,'',false),
   array('A &amp; B',3,'A &amp;',true),array('ä&#x1F600;ö',2,'ä&#x1F600;',true),array('&lt;em&gt;text',1,'&lt;',true),array('&bogus;tail',3,'&bo',true),array('&amp;amp;tail',2,'&amp;a',true),array('&#000000228;Z',1,'&#000000228;',true),
   array("A\xc3Z",2,"A\xef\xbf\xbd",true),array('Ä😀tail',0,'Ä😀tail',false),array('Ä😀tail',-1,'Ä😀tail',false),array('Ä😀tail',PHP_INT_MAX,'Ä😀tail',false),
   array('äö &lt;!--break--&gt;rest',100,'äö ',true),array('&lt;!--break--&gt;rest',100,'',true),
   array('12'.$b.'äöü'.$bc.'tail',3,'12'.$b.'äöü'.$bc,true),array('12'.$b.'äöü'.$bc.'tail',2,'12',true),array($b.'äöü'.$bc,1,$b.'äöü'.$bc,false),
   array('12'.$b.'text'.$b.'nested'.$bc.'tail'.$bc.'END',40,'12'.$b.'text'.$b.'nested'.$bc.'tail'.$bc,true),
   array($b.'ä&lt;!--break--&gt;ö'.$bc.'END',100,$b.'ä&lt;!--break--&gt;ö'.$bc,true),
   array($code.'literal [b] text'.$cc.'END',20,$code.'literal [b] text'.$cc,true),
   array('[list:abc1234567][*:abc1234567]ä[*:abc1234567]ö[/list:u:abc1234567]END',20,'[list:abc1234567][*:abc1234567]ä[*:abc1234567]ö[/list:u:abc1234567]',true),
   array(str_repeat('[b',16000),3,'[b[',true),array(str_repeat('[b]',2000).str_repeat('[/i]',2000),3,'[b]',true),
   array('[b][i]text[/b]tail[/i]END',7,'[b][i]text[/b]tail[/i]',true)
  );
  foreach($vectors as$v){$raw=$v[0];$before=$raw;$trimmed=null;$out=$n->trimText($raw,$v[1],$trimmed);nt_check($out===$v[2]&&$trimmed===$v[3],'Actual excerpt boundary case '.$cases.' differs: '.json_encode(array(substr($out,0,90),$trimmed)));nt_check($raw===$before&&preg_match('//u',$out)===1,'Stored source unchanged and preview remains UTF-8');$cases++;}
  foreach(array(array('Aä😀Z',2,'Aä'),array('A &amp; B',3,'A &amp;'),array('<b>Ä😀Z</b>',2,'Ä😀'),array('&lt;b&gt;Ä&lt;/b&gt;',3,'&lt;b&gt;'),array("A\xc3Z",2,"A\xef\xbf\xbd"),array('short',100,'short'))as$v){nt_check($n->trimString($v[0],$v[1])===$v[2],'Plain label helper keeps Unicode, one entity layer and escaping');$cases++;}
  $pipeline=array(
   array('Aä😀Z',2,'Aä',true),array('A &amp; B',3,'A &amp;',true),
   array('12'.$b.'text'.$b.'nested'.$bc.'tail'.$bc.'END',40,'12'.$b.'text'.$b.'nested'.$bc.'tail'.$bc,true),
   array('äö &lt;!--break--&gt;rest',100,'äö ',true),array($b.'äöü'.$bc,1,$b.'äöü'.$bc,false),array('Ä😀',2,'Ä😀',false)
  );
  foreach($pipeline as$v){foreach(array('news.tpl','portal_body.tpl','news_200_rss_body.tpl')as$file){
   $template=nt_template($file);$n=nt_module($template);$n->config['news_item_trim']=$v[1];$n->syndication=$file==='news_200_rss_body.tpl';$row=nt_rows()[0];$row['post_text']=$v[0];$row['bbcode_uid']='abc1234567';$before=serialize($row);$n->prepareArticles(array($row),true);$bound=$template->_tpldata['articles.'][0];$expected=$n->parseMessage($v[2].($v[3]?' ... ':''),'abc1234567');
   nt_check($bound['BODY']===$expected,'Actual article pipeline parses the expected complete excerpt');nt_check(($bound['READ_MORE_LINK']!=='')===$v[3],'Read-more link appears only for a shortened article');$html=nt_html($template);nt_check(preg_match('//u',$html)===1&&strpos($html,"\xef\xbf\xbd")===false,'Actual News/portal/RSS template contains no damaged Unicode');nt_check(serialize($row)===$before,'Preparing/rendering never modifies source row');$views++;
  }}
 }
 echo 'News excerpts: ',$cases,' boundary cases, ',$views,' actual parser/XS views, ',$nt_checks," assertions PASS; UTF-8, stored entities, nested BBCode/code/lists, manual breaks, exact limits, read-more links and unchanged source.\n";
}finally{restore_error_handler();}
