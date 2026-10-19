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
	require_once dirname(__FILE__) . '/functions_kb_rating_storage.php';
	try {
		$outcome = kb_rate_article($db, isset($_POST['k']) ? $_POST['k'] : (isset($_GET['k']) ? $_GET['k'] : null), isset($_POST['rating']) ? $_POST['rating'] : null);
	} catch (PhpbbKbPublicationException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	if ($outcome['duplicate']) { message_die(GENERAL_MESSAGE, $lang['Rerror']); }
	$article = $outcome['article']; $article_id = (int)$article['article_id']; $category_id = (int)$article['article_category_id'];
	$nrating = rtrim(rtrim((string)$article['rating_average'], '0'), '.');
	$conf = str_replace(array('{filename}','{rate}','{newrating}'), array(phpbb_stored_text($article['article_title']),$outcome['rating'],$nrating), $lang['Rconf']);
	$article_url = htmlspecialchars(append_sid($phpbb_root_path . 'kb.' . $phpEx . '?mode=article&k=' . $article_id), ENT_QUOTES, 'UTF-8', false);
	$category_url = htmlspecialchars(append_sid($phpbb_root_path . 'kb.' . $phpEx . '?mode=cat&cat=' . $category_id), ENT_QUOTES, 'UTF-8', false);
	$template->assign_vars(array('META'=>'<meta http-equiv="refresh" content="3;url=' . $article_url . '">'));
	$message = $conf . '<br /><br />' . sprintf($lang['Click_return_rate'], '<a href="' . $article_url . '">', '</a>');
	$message .= '<br /><br />' . sprintf($lang['Click_return_forum'], '<a href="' . $category_url . '">', '</a>');
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
