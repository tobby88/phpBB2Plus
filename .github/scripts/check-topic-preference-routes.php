<?php
// Routing checks only. The former combined SQLite preference/mail simulator's
// behavioral cases now run in check-topic-preferences-native.php and the real
// mailer fixture check-topic-notifications-native.php on disposable databases.
$root=dirname(dirname(__DIR__)).'/phpBB2/';$checks=0;
foreach(array('ajax.php','viewtopic.php','includes/functions_post.php')as$file){$source=file_get_contents($root.$file);if(strpos($source,"phpbb_topic_preference(\$db, \$topic_id, 'watch'")===false){throw new RuntimeException('Interactive watch writer bypasses shared worker: '.$file);}$checks++;}
$bookmark=file_get_contents($root.'includes/functions_bookmark.php');foreach(array('is_bookmark_set','set_bookmark','remove_bookmark')as$name){if(!preg_match('/function '.preg_quote($name,'/').'\(.*?phpbb_bookmark_preference/s',$bookmark)){throw new RuntimeException('Bookmark route bypasses shared preference worker');}$checks++;}
$endpoint=file_get_contents($root.'topic_watch.php');if(strpos($endpoint,"phpbb_topic_preference(\$db, \$topic_id, 'watch', false)")===false){throw new RuntimeException('Confirmation endpoint bypasses shared worker');}$checks++;
echo 'Topic preference route wiring: '.$checks." checks PASS; watch, bookmark and confirmation writers share the native-tested worker.\n";
