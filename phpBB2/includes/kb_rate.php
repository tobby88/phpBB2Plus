<?php
/***************************************************************************
 *                             kb_rate.php
 *                            -------------------
 *   begin                : April, 2003
 *   copyright            : (C) 2002 MX-System
 *   email                : support@mx-system.com
 *   description		  : implementing ratings
 *	 Author				  : Haplo (jonohlsson@hotmail.com)
 *	 credit				  : pafiledb 
 *
 *   $Id: kb_rate.php,v 1.4 2004/05/02 08:25:02 jonohlsson Exp $
 *
 ***************************************************************************/
// ---------------------------------------------------------------------START
// This file adds rating to the module
// -------------------------------------------------------------------------
if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}

//
// Start initial var setup
//
$category_id = (isset($_REQUEST['cat']) && is_scalar($_REQUEST['cat'])) ? intval($_REQUEST['cat']) : 0;


if (isset($_REQUEST['k']) && is_scalar($_REQUEST['k']))
{
	$article_id = intval($_REQUEST['k']);
}
else
{
	message_die(GENERAL_MESSAGE, 'no article');
}

$rate = (isset($_POST['rate']) && is_scalar($_POST['rate'])) ? (string) $_POST['rate'] : '';
$rating = (isset($_POST['rating']) && is_scalar($_POST['rating'])) ? intval($_POST['rating']) : 0;

$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? max(0, min(1000000, intval($_GET['start']))) : 0;
//
// End initial var setup
//

   include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	make_jumpbox($phpbb_root_path .'viewforum.'.$phpEx, $category_id);
	
	//load header
	include ($phpbb_root_path ."includes/kb_header.".$phpEx);

$template->set_filenames(array(
	'body' => 'kb_rate_body.tpl')
);

$sql = "SELECT * FROM " . KB_ARTICLES_TABLE . " WHERE article_id = '" . $article_id . "'";

if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Couldnt Query Article info', '', __LINE__, __FILE__, $sql);
}
$article = $db->sql_fetchrow($result);
if (!$article)
{
	message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
}

$can_rate_unapproved = $is_admin || ($userdata['session_logged_in'] && intval($article['article_author_id']) === intval($userdata['user_id']));
if ((int) $article['approved'] !== 1 && !$can_rate_unapproved)
{
	message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
}

if (empty($kb_config['allow_rating']) || (!$userdata['session_logged_in'] && empty($kb_config['allow_anonymos_rating'])))
{
	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
}

$category_id = (int) $article['article_category_id'];
$sql = "SELECT * FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = " . $category_id;
if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Couldnt Query category info for this article', '', __LINE__, __FILE__, $sql);
}
$category = $db->sql_fetchrow($result);

$ipaddy= getenv ("REMOTE_ADDR");

if ($rate == 'dorate')
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}
	if ($rating < 1 || $rating > 10)
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	$conf = str_replace("{filename}", phpbb_stored_text($article['article_title']), $lang['Rconf']);
	$conf = str_replace("{rate}", $rating, $conf);

	$ipaddy = $db->sql_escape((string) getenv('REMOTE_ADDR'));
	$user_id = intval($userdata['user_id']);
	$duplicate_conditions = array();
	if ($kb_config['votes_check_ip'] == 1)
	{
		$duplicate_conditions[] = "votes_ip = '$ipaddy'";
	}
	if ($kb_config['votes_check_userid'] == 1)
	{
		$duplicate_conditions[] = "votes_userid = $user_id";
	}
	$duplicate_sql = empty($duplicate_conditions) ? '0 = 1' : '(' . implode(' OR ', $duplicate_conditions) . ')';
	$sql = "INSERT INTO " . KB_VOTES_TABLE . " (votes_ip, votes_userid, votes_file)
		SELECT '$ipaddy', $user_id, $article_id
		WHERE NOT EXISTS (SELECT 1 FROM " . KB_VOTES_TABLE . " WHERE votes_file = $article_id AND $duplicate_sql)";

    if ( !($insert = $db->sql_query($sql)) )
    {
		message_die(GENERAL_ERROR, 'Couldnt Update rating table', '', __LINE__, __FILE__, $sql);
    }
	if (!$db->sql_affectedrows())
	{
		message_die(GENERAL_MESSAGE, $lang['Rerror']);
	}

    $sql = "UPDATE " . KB_ARTICLES_TABLE . " SET article_rating = article_rating + $rating, article_totalvotes = article_totalvotes + 1 WHERE article_id = $article_id";

    if ( !($update = $db->sql_query($sql)) )
    {
        message_die(GENERAL_ERROR, 'Couldnt Update rating table', '', __LINE__, __FILE__, $sql);
    }

    $sql = "SELECT * FROM " . KB_ARTICLES_TABLE . " WHERE article_id = '" . $article_id . "'";

    if ( !($result = $db->sql_query($sql)) )
    {
        message_die(GENERAL_ERROR, 'Couldnt Update rating table', '', __LINE__, __FILE__, $sql);
    }

	$article = $db->sql_fetchrow($result);

	if ($article['article_rating'] == 0 or $article['article_totalvotes'] == 0)
	{
		$nrating = 0; 
	}
	else
	{
		$nrating = round($article['article_rating']/($article['article_totalvotes']), 3); 
	}

	$conf = str_replace("{newrating}", $nrating, $conf);

	$template->assign_vars(array(
		"META" => '<meta http-equiv="refresh" content="3;url=' . htmlspecialchars(append_sid($phpbb_root_path . "kb.$phpEx?action=url&amp;k=" . $article_id), ENT_QUOTES, 'UTF-8') . '">')
	);
	$message = $conf . "<br /><br />" . sprintf($lang['Click_return_rate'], "<a href=\"" . append_sid($phpbb_root_path . "kb.$phpEx?mode=article&amp;k=$article_id") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_forum'], "<a href=\"" . append_sid("kb.$phpEx?action=cat&amp;cat=$category_id") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);  

}
else
{
	$rateinfo = str_replace("{filename}", phpbb_stored_text($article['article_title']), $lang['Rateinfo']);

	$template->assign_block_vars("rate", array());

//
// Send variables to template (the associated *.tpl file)
//
	$template->assign_vars(array(
		'S_RATE_ACTION' => append_sid($phpbb_root_path . "kb.$phpEx?mode=rate&amp;cat=$category_id&amp;k=$article_id"),
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		'L_RATE' => $lang['Rate'],
		'L_RERROR' => $lang['Rerror'],
		'L_R1' => $lang['R1'],
		'L_R2' => $lang['R2'],
		'L_R3' => $lang['R3'],
		'L_R4' => $lang['R4'],
		'L_R5' => $lang['R5'],
		'L_R6' => $lang['R6'],
		'L_R7' => $lang['R7'],
		'L_R8' => $lang['R8'],
		'L_R9' => $lang['R9'],
		'L_R10' => $lang['R10'],
		'RATEINFO' => $rateinfo, 
		'ID' => $article_id) 
	);
}

?>
