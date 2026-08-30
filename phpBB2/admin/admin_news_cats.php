<?php
/***************************************************************************
*                               admin_news_cats.php
*                              -------------------
*     begin                : Sunday, 19th Jan 2003
*
****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

/**************************************************************************
*  This file will be used for modifying the smiley settings for a board.
**************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
//
// First we do the setmodules stuff for the admin cp.
//
if( !empty($setmodules) )
{
  $filename = basename(__FILE__);
  $module['News Admin']['Categories'] = $filename;

  return;
}

//
// Load default header
//

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

include_once ($phpbb_root_path . 'includes/news_data.' . $phpEx );

//
// Check to see what mode we should operate in.
//
if (isset($_POST['mode']))
{
  $mode = is_scalar($_POST['mode']) ? (string) $_POST['mode'] : '';
}
else if (isset($_GET['mode']))
{
  $mode = is_scalar($_GET['mode']) ? (string) $_GET['mode'] : '';
}
else
{
  $mode = "";
}
if (!in_array($mode, array('', 'delete', 'edit', 'save', 'savenew'), true))
{
  message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

$dir = @opendir($phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path']);
$category_images = array();

if( !$dir )
{
   message_die(GENERAL_ERROR, "Couldn't find news images", "", __LINE__, __FILE__, "The news images were not found" );
}

while($file = @readdir($dir))
{
  if( !@is_dir($phpbb_root_path . $board_config['news_path'] . '/' . $file) )
  {
    $img_size = @getimagesize($phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'] . '/' . $file);

    if( is_array($img_size) && !empty($img_size[0]) && !empty($img_size[1]) )
    {
      $category_images[] = $file;
    }
  }
}

@closedir($dir);

if( !empty( $category_images ) )
{
  sort( $category_images );
}
else
{
  message_die(GENERAL_ERROR, "Couldn't find usable news images");
}

if( isset($_POST['add']) || isset($_GET['add']) )
{
  //
  // Admin has selected to add a smiley.
  //

  $template->set_filenames(array(
    "body" => "admin/news_cat_edit_body.tpl")
  );

  $filename_list = "";
  for( $i = 0; $i < count($category_images); $i++ )
  {
    $safe_filename = htmlspecialchars((string) $category_images[$i], ENT_QUOTES, 'UTF-8');
    $filename_list .= '<option value="' . $safe_filename . '">' . $safe_filename . '</option>';
  }

  $s_hidden_fields = '<input type="hidden" name="mode" value="savenew" />' . phpbb_admin_session_field();

  $template->assign_vars(array(
    "L_NEWS_TITLE"     => $lang['Add_news_categories'],
    "L_NEWS_CONFIG"   => $lang['News_Configuration'],
    "L_NEWS_EXPLAIN"   => $lang['News_Add_Description'],
    "L_NEWS_ICON"     => $lang['Icon'],
    "L_CATEGORY"    => $lang['Category'],
    "L_SUBMIT"     => $lang['Submit'],
    "L_RESET"     => $lang['Reset'],

    "I_NEWS_IMG"     => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'] . '/' . $category_images[0],

    "S_NEWS_ACTION"   => append_sid("admin_news_cats.$phpEx"),
    "S_HIDDEN_FIELDS"   => $s_hidden_fields,
    "S_FILENAME_OPTIONS"   => $filename_list,
    "S_SMILEY_BASEDIR"   => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'])
  );

  $template->pparse("body");
}
else if ( $mode != "" )
{
  switch( $mode )
  {
    case 'delete':
      //
      // Admin has selected to delete a category.
      //

      $news_id = ( isset($_POST['id']) && is_scalar($_POST['id']) ) ? (int) $_POST['id'] : ((isset($_GET['id']) && is_scalar($_GET['id'])) ? (int) $_GET['id'] : 0);

      if (isset($_POST['cancel']))
      {
        redirect(append_sid("admin_news_cats.$phpEx"));
      }

      if ($news_id <= 0)
      {
        message_die(GENERAL_MESSAGE, $lang['No_news_category_selected']);
      }

      if (!isset($_POST['confirm']))
      {
        $template->set_filenames(array(
          'body' => 'admin/confirm_body.tpl')
        );

        $hidden_fields = '<input type="hidden" name="mode" value="delete" />' .
          '<input type="hidden" name="id" value="' . $news_id . '" />' . phpbb_admin_session_field();

        $template->assign_vars(array(
          'MESSAGE_TITLE' => $lang['Confirm'],
          'MESSAGE_TEXT' => $lang['Confirm_delete_news_category'],
          'L_YES' => $lang['Yes'],
          'L_NO' => $lang['No'],
          'S_CONFIRM_ACTION' => append_sid("admin_news_cats.$phpEx"),
          'S_HIDDEN_FIELDS' => $hidden_fields)
        );
        $template->pparse('body');
        break;
      }

      if (!isset($_POST['id']) || !is_scalar($_POST['id']))
      {
        message_die(GENERAL_MESSAGE, $lang['No_news_category_selected']);
      }
      $news_id = (int) $_POST['id'];
      if ($news_id <= 0)
      {
        message_die(GENERAL_MESSAGE, $lang['No_news_category_selected']);
      }
      phpbb_admin_require_post_session();

      $sql = "DELETE FROM " . NEWS_TABLE . "
        WHERE news_id = " . $news_id;

      $result = $db->sql_query($sql);
      if( !$result )
      {
        message_die(GENERAL_ERROR, "Couldn't delete news category", "", __LINE__, __FILE__, $sql);
      }

      $sql = "UPDATE " . TOPICS_TABLE . " SET news_id=0 WHERE news_id = " . $news_id;

      $result = $db->sql_query($sql);
      if( !$result )
      {
        message_die(GENERAL_ERROR, "Couldn't update topics news category", "", __LINE__, __FILE__, $sql);
      }

      $message = $lang['Category_Deleted'] . "<br /><br />" . sprintf($lang['Click_return_newsadmin'], "<a href=\"" . append_sid("admin_news_cats.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

      message_die(GENERAL_MESSAGE, $message);
      break;

    case 'edit':
      //
      // Admin has selected to edit a smiley.
      //

      $news_id = ( isset($_POST['id']) && is_scalar($_POST['id']) ) ? (int) $_POST['id'] : ((isset($_GET['id']) && is_scalar($_GET['id'])) ? (int) $_GET['id'] : 0);

      if ($news_id <= 0)
      {
        message_die(GENERAL_MESSAGE, $lang['No_news_category_selected']);
      }

      $sql = "SELECT *
        FROM " . NEWS_TABLE . "
        WHERE news_id = " . $news_id;

      $result = $db->sql_query($sql);
      if( !$result )
      {
        message_die(GENERAL_ERROR, 'Could not obtain news information', "", __LINE__, __FILE__, $sql);
      }
      $category_data = $db->sql_fetchrow($result);

      if (!$category_data)
      {
        message_die(GENERAL_MESSAGE, $lang['No_news_category_selected']);
      }

      $filename_list = "";
      $category_edit_img = in_array($category_data['news_image'], $category_images, true) ? $category_data['news_image'] : $category_images[0];
      for( $i = 0; $i < count($category_images); $i++ )
      {
        if( $category_images[$i] == $category_edit_img )
        {
          $category_selected = "selected=\"selected\"";
        }
        else
        {
          $category_selected = "";
        }

        $safe_filename = htmlspecialchars((string) $category_images[$i], ENT_QUOTES, 'UTF-8');
        $filename_list .= '<option value="' . $safe_filename . '" ' . $category_selected . '>' . $safe_filename . '</option>';
      }

      $template->set_filenames(array(
        "body" => "admin/news_cat_edit_body.tpl")
      );

      $s_hidden_fields = '<input type="hidden" name="mode" value="save" /><input type="hidden" name="news_id" value="' . (int) $category_data['news_id'] . '" />' . phpbb_admin_session_field();


      $template->assign_vars(array(
        "NEWS_CATEGORY" => htmlspecialchars((string) $category_data['news_category']),
        "NEWS_ICON" => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'] . '/' . $category_edit_img,

        "L_NEWS_TITLE" => $lang['News_Editing_Utility'],
        "L_NEWS_CONFIG" => $lang['News_Config'],
        "L_NEWS_EXPLAIN" => $lang['News_Explain'],
        "L_NEWS_ICON" => $lang['Icon'],
        "L_CATEGORY" => $lang['Category'],
        "L_SUBMIT" => $lang['Submit'],
        "L_RESET" => $lang['Reset'],

        "I_NEWS_IMG" => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'] . '/'. $category_edit_img,

        "S_SMILEY_ACTION" => append_sid("admin_news_cats.$phpEx"),
        "S_HIDDEN_FIELDS" => $s_hidden_fields,
        "S_FILENAME_OPTIONS" => $filename_list,
        "S_SMILEY_BASEDIR" => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path'])
      );

      $template->pparse("body");
      break;

    case "save":
      //
      // Admin has submitted changes while editing a smiley.
      //

      //
      // Get the submitted data, being careful to ensure that we only
      // accept the data we are looking for.
      //
      phpbb_admin_require_post_session();
      $news_category = ( isset($_POST['category']) && is_scalar($_POST['category']) ) ? substr(trim((string) $_POST['category']), 0, 70) : '';
      $news_image = ( isset($_POST['image_url']) && is_scalar($_POST['image_url']) ) ? trim((string) $_POST['image_url']) : '';
      $news_id = ( isset($_POST['news_id']) && is_scalar($_POST['news_id']) ) ? intval($_POST['news_id']) : 0;

      // If no code was entered complain ...
      if ($news_category == '' || $news_image == '' || $news_id <= 0)
      {
        message_die(MESSAGE, $lang['Fields_empty']);
      }

      if (!in_array($news_image, $category_images, true))
      {
        message_die(GENERAL_MESSAGE, $lang['Fields_empty']);
      }

      $news_category_sql = $db->sql_escape($news_category);
      $news_image_sql = $db->sql_escape($news_image);

      //
      // Proceed with updating the news table.
      //
      $sql = "UPDATE " . NEWS_TABLE . "
        SET  news_category = '$news_category_sql', news_image = '$news_image_sql'
        WHERE news_id = $news_id";
      if( !($result = $db->sql_query($sql)) )
      {
        message_die(GENERAL_ERROR, "Couldn't update news info", "", __LINE__, __FILE__, $sql);
      }

      $message = $lang['Category_Updated'] . "<br /><br />" . sprintf( $lang['Click_return_newsadmin'], "<a href=\"" . append_sid("admin_news_cats.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

      message_die(GENERAL_MESSAGE, $message);
      break;

    case "savenew":
      //
      // Admin has submitted changes while adding a new smiley.
      //

      //
      // Get the submitted data being careful to ensure the the data
      // we recieve and process is only the data we are looking for.
      //
      phpbb_admin_require_post_session();
      $news_category = ( isset($_POST['category']) && is_scalar($_POST['category']) ) ? substr(trim((string) $_POST['category']), 0, 70) : '';
      $news_image = ( isset($_POST['image_url']) && is_scalar($_POST['image_url']) ) ? trim((string) $_POST['image_url']) : '';

      // If no code was entered complain ...
      if ($news_category == '' || $news_image == '' )
      {
        message_die(MESSAGE, $lang['Fields_empty']);
      }

      if (!in_array($news_image, $category_images, true))
      {
        message_die(GENERAL_MESSAGE, $lang['Fields_empty']);
      }

      $news_category_sql = $db->sql_escape($news_category);
      $news_image_sql = $db->sql_escape($news_image);

      //
      // Save the data to the smiley table.
      //
      $sql = "INSERT INTO " . NEWS_TABLE . " ( news_image, news_category)
        VALUES ( '$news_image_sql', '$news_category_sql')";
      $result = $db->sql_query($sql);
      if( !$result )
      {
        message_die(GENERAL_ERROR, "Couldn't insert new category", "", __LINE__, __FILE__, $sql);
      }

      $message = $lang['Category_Added'] . "<br /><br />" . sprintf($lang['Click_return_newsadmin'], "<a href=\"" . append_sid("admin_news_cats.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

      message_die(GENERAL_MESSAGE, $message);
      break;
  }
}
else
{
  //
  // This is the main display of the page before the admin has selected
  // any options.
  //
  $data_access = new NewsDataAccess( $phpbb_root_path );
  $news_cats = $data_access->fetchCategories( );

  $template->set_filenames(array(
    "body" => "admin/news_cat_list_body.tpl")
  );

  $template->assign_vars(array(
    'L_ACTION' => $lang['Action'],
    'L_NEWS_TITLE' => $lang['News_Editing_Utility'],
    'L_NEWS_TEXT' => $lang['News_Explain'],
    'L_DELETE' => $lang['Delete'],
    'L_EDIT' => $lang['Edit'],
    'L_NEWS_ADD' => $lang['Add_new_category'],
    'L_ICON' => $lang['Icon'],
    'L_CATEGORY' => $lang['Category'],
    'L_TOPICS' => $lang['Topics'],

    'S_HIDDEN_FIELDS' => isset($s_hidden_fields) ? $s_hidden_fields : '',
    'S_NEWS_ACTION' => append_sid("admin_news_cats.$phpEx"))
  );

  //
  // Loop throuh the rows of smilies setting block vars for the template.
  //
  for($i = 0; $i < count($news_cats); $i++)
  {
    //
    // Replace htmlentites for < and > with actual character.
    //

    $row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
    $row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

    $stored_news_image = in_array($news_cats[$i]['news_image'], $category_images, true) ? $news_cats[$i]['news_image'] : $category_images[0];
    $template->assign_block_vars("news_cats", array(
      'ROW_COLOR' => '#' . $row_color,
      'ROW_CLASS' => $row_class,

      'TOPIC_COUNT' => (int) $news_cats[$i]['topic_count'],

      'CATEGORY_IMG' => $phpbb_root_path . 'templates/'.$theme['template_name'].'/'.$board_config['news_path']. '/' . $stored_news_image,
      'L_CATEGORY' => htmlspecialchars((string) $news_cats[$i]['news_category'], ENT_QUOTES, 'UTF-8'),

      'U_NEWS_EDIT' => append_sid("admin_news_cats.$phpEx?mode=edit&amp;id=" . $news_cats[$i]['news_id']),
      'U_NEWS_DELETE' => append_sid("admin_news_cats.$phpEx?mode=delete&amp;id=" . $news_cats[$i]['news_id']))
    );
  }

  //
  // Spit out the page.
  //
  $template->pparse("body");
}

//
// Page Footer
//
include('./page_footer_admin.'.$phpEx);

?>
