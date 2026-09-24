<?php
// Real common.php request normalization, excluding configuration/session/DB
// bootstrap. Call once per simulated HTTP hop with that hop's raw browser POST.
function profile_fixture_request($post)
{
    global $_GET, $_POST, $_COOKIE, $_REQUEST, $HTTP_GET_VARS, $HTTP_POST_VARS, $HTTP_COOKIE_VARS;
    static $normalization = null;
    if ($normalization === null)
    {
        $source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/common.php');
        $begin = strpos($source, '$_GET = phpbb_addslashes_recursive($_GET);');
        $end_marker = '$HTTP_COOKIE_VARS = $_COOKIE;'; $end = strpos($source, $end_marker, $begin);
        if ($begin === false || $end === false) { throw new RuntimeException('Actual bootstrap input normalization'); }
        $normalization = substr($source, $begin, $end + strlen($end_marker) - $begin);
    }
    $_GET = $_COOKIE = array(); $_POST = $_REQUEST = $post;
    eval($normalization);
}
