<?php
// Exercise the real list/statistic formatting blocks, not a copied formatter.
$source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/functions_kb.php');
function kr_render_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
kr_render_check(preg_match_all('/\$article_rating = [^;]+;\s*\$article_totalvotes = [^;]+;/',$source,$assignments)===2,'Both real rating list assignments');
kr_render_check(preg_match_all('/if \(\$article_rating == 0 \|\| \$article_totalvotes == 0\)[\s\S]*?(?=\$template->assign_block_vars)/',$source,$bodies)===2,'Both real rating list display bodies');
foreach($assignments[0]as$i=>$assignment){
 foreach(array('2147483648','9223372036854775808','18446744073709551614')as$count){
  // A double is sufficient for a two-decimal display, never for stored counters.
  $article=array('article_rating'=>sprintf('%.0f',(float)$count*10),'article_totalvotes'=>$count);
  eval($assignment.$bodies[0][$i]);
  kr_render_check((string)$rating_votes===$count&&$rating==10&&strpos($rating_message,$count.' votes)')!==false,'Exact wide vote count and correct average in both real list variants');
 }
 $article=array('article_rating'=>'0.0000','article_totalvotes'=>'0');eval($assignment.$bodies[0][$i]);
 kr_render_check($rating_message===''&&$rating==0,'Empty rating remains empty');
}
echo "KB rating rendering: exact wide vote counters and two-decimal averages in both actual list variants passed.\n";
