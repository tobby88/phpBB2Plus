<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_comment_text.php';

function pafiledb_license_terms($text)
{
    global $board_config;
    $before=$board_config;
    try {
        // Terms historically support HTML independently of post/comment HTML.
        // Retain document formatting through the forum's maintained attribute
        // and URL sanitizer, with no forms, embedded documents or executable tags.
        $board_config=(array)$board_config;
        $board_config['allow_html_tags']='p,br,b,strong,i,em,u,s,strike,pre,code,blockquote,ul,ol,li,dl,dt,dd,h1,h2,h3,h4,h5,h6,hr,a,span,div,table,thead,tbody,tfoot,tr,th,td,img';
        return nl2br(pafiledb_comment_safe_html((string)$text,true));
    } finally { $board_config=$before; }
}
