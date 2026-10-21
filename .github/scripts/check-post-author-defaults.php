<?php
require dirname(dirname(__DIR__)).'/update/post_author_storage_migration.php';
$cases=array(
 array('5.6.51','ordinary',true),array('8.0.43','ordinary',true),
 array('10.1.48-MariaDB','ordinary',true),array('5.5.5-10.1.48-MariaDB','ordinary',true),
 array('10.2.1-MariaDB','ordinary',false),array('10.2.6-MariaDB',"'quoted'",false),
 array('10.2.7-MariaDB',"'quoted'",true),array('10.2.7-MariaDB','quoted',false),
 array('10.11.14-MariaDB',"''",true),array('10.11.14-MariaDB',"'NULL'",true),
 array('10.11.14-MariaDB','NULL',false),array('10.11.14-MariaDB',null,false),
 array('10.11.14-MariaDB',"concat('Guest',' name')",false),
 array('10.11.14-MariaDB',"'concat(''Guest'','' name'')'",true),
 array('10.11.14-MariaDB',"'a' + 'b'",false),array('10.11.14-MariaDB',"('a')",false),
 array('5.5.5-10.11.14-MariaDB',"'a''b\\c'",true),array('11.4.5-MariaDB-enterprise',"'😀'",true),
 array('future-MariaDB',"'quoted'",false),array(null,"'quoted'",false)
);
foreach($cases as$case){if(plus_post_author_default_is_literal($case[0],$case[1])!==$case[2]){throw new RuntimeException('Default metadata classifier mismatch');}}
$source=file_get_contents(dirname(dirname(__DIR__)).'/update/post_author_storage_migration.php');
foreach(array("$"."fields['post_username']['Extra'] !== ''",'DEFAULT(post_username)',"mysqli_character_set_name($"."db) !== 'utf8mb4'",'Expression or ambiguous post author defaults require manual review')as$required){if(strpos($source,$required)===false){throw new RuntimeException('Post author preservation boundary missing');}}
echo 'Post author default metadata: '.count($cases)." MySQL/MariaDB literal/expression/version vectors passed; MySQL expressions remain rejected by Extra guard.\n";
