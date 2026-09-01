<?php
/***************************************************************************
 *                                news.inc
 *                            -------------------
 *   begin                : July 17th, 2003
 *   copyright            : (C) 2003 CodeMonkeyX.net
 *   email                : webmaster@codemonkeyx.net
 *
 *   version              : 0.1
 *
 ***************************************************************************/


if ( !defined('IN_PHPBB') )
{
  die("Hacking attempt");
}

require_once ($phpbb_root_path.'includes/news_common.' . $phpEx);
require_once ($phpbb_root_path.'includes/news_data.' . $phpEx);
require_once ($phpbb_root_path.'includes/bbcode.' . $phpEx);
require_once ($phpbb_root_path.'includes/functions_color_groups.' . $phpEx);

/**
 * Class which displays news content.
 */
class NewsModule
{
  /**
  * news data access abstraction object.
  * @var object
  */
  var $data;

  /**
  * path to phpbb.
  * @var object
  */
  var $root_path;

  /**
  * default file extension.
  * @var object
  */
  var $phpEx;

  var $template;
  var $config;
  var $name;
  var $item_count;
  var $syndication;
  var $index_file;

  /**
  * Class constructor.
  *
  * @param string   (optional) location of the templates directory.
  *
  * @return void
  *
  * @access public
  */
  function __construct( $root_path )
  {
    $this->NewsModule( $root_path );
  }

  function NewsModule( $root_path )
  {
    global $CFG, $db, $phpEx, $template, $board_config;
    
    $this->root_path  = phpbb_board_url();
    $this->phpEx      = $phpEx;
    $this->template   = &$template;
    $this->config     = &$board_config;
    $this->name = 'news';
    $this->item_count = 1;
	$this->syndication = false;
    
	$index_name = phpbb_profile_image_name(isset($this->config['news_index_file']) ? $this->config['news_index_file'] : '');
	if ($index_name === '')
	{
	  $index_name = 'news_index.php';
	}
	$base_url = isset($this->config['news_base_url']) ? phpbb_profile_http_url($this->config['news_base_url']) : '';
	$this->index_file = $base_url . rawurlencode($index_name);
    
    $this->setVariables( array(
        'INDEX_FILE' => $this->index_file,
        'ROOT_PATH' => $this->root_path
        ));

    $this->data = new NewsDataAccess( $root_path );
  }

  function htmlText( $value )
  {
    return htmlspecialchars(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  function imageUrl( $filename )
  {
    global $theme;

    $template_name = phpbb_profile_asset_path(isset($theme['template_name']) ? $theme['template_name'] : '');
    $news_path = phpbb_profile_asset_path(isset($this->config['news_path']) ? $this->config['news_path'] : '');
    $filename = phpbb_profile_image_name($filename);
    if ($template_name === '' || $news_path === '' || $filename === '' || !preg_match('/\.(?:gif|jpe?g|png)$/iD', $filename))
    {
      return $this->root_path . 'images/spacer.gif';
    }

    return $this->root_path . 'templates/' . $template_name . '/' . $news_path . '/' . rawurlencode($filename);
  }

  function assignToBlock( $block ) 
  { 
    $this->template->assign_var_from_handle( $block, $this->name );    
  } 
  /**
  * prepares a list of articles.
  *
  * @param integer (optional) the article id to the article to be displayed.
  *
  * @return void
  *
  * @access private
  */
  function prepareArticles( $articles, $show_abstract = false )
  {
    global $CFG, $lang, $is_auth, $theme, $userdata, $board_config, $plus_config;

    if( is_array( $articles ) )
    {
      foreach( $articles as $article )
      {
        $trimmed = false;
		$topic_id = (int) $article['topic_id'];

        // Trim the post body if needed.
        if( $show_abstract && $this->config['news_item_trim'] > 0 )
        {
          $article['post_abstract'] = $this->trimText( $article['post_text'], $this->config['news_item_trim'], $trimmed );
          $article['post_abstract'] = $this->parseMessage( $article['post_abstract'] . ' ... ', $article['bbcode_uid'] );
        }

        $article['post_text'] = $this->parseMessage( $article['post_text'], $article['bbcode_uid'] );

	init_display_post_attachments($article['topic_attachment'], $article);

	$sql = "";

	$dateformat = ($userdata['user_id'] == ANONYMOUS) ? $board_config['default_dateformat'] : $userdata['user_dateformat'];
	$timezone = ($userdata['user_id'] == ANONYMOUS) ? $board_config['board_timezone'] : $userdata['user_timezone'];
	$recent_title_long = $this->root_path . 'viewtopic.' . $this->phpEx . '?t=' . $topic_id;
	$recent_title_short = $this->root_path . 'ftopic' . $topic_id . '.html';
	
	$recent_title = ($plus_config['enable_shorturls']) ? $recent_title_short : $recent_title_long;
	
		$article_title = $this->syndication ? $this->xmlText($article['topic_title']) : $this->htmlText($article['topic_title']);
		$article_category = $this->syndication ? $this->xmlText($article['news_category']) : $this->htmlText($article['news_category']);
		$article_body = ($show_abstract && $trimmed) ? $article['post_abstract'] : $article['post_text'];
		if ($this->syndication)
		{
			$article_body = str_replace(']]>', ']]]]><![CDATA[>', $article_body);
		}

        $this->setBlockVariables( 'articles', array(
                    'L_TITLE' => $article_title,
                    'L_TITLE_ICON' => get_icon_title((int) $article['topic_icon'], 0, (int) $article['topic_type']).'&nbsp;',
					'ID' => $topic_id,
					'KEY' => isset($article['article_key']) ? $this->htmlText($article['article_key']) : '',
                    'DAY' => $this->getDay( $article['topic_time'] ),
                    'MONTH' => $this->getMonth( $article['topic_time'] ),
                    'YEAR' => $this->getYear( $article['topic_time'] ),
					'CATEGORY' => $article_category,
					'FORUM_NAME' => $article_category,
                    'CAT_ID' => (int) $article['news_id'],
                    'COUNT_VIEWS' => (int) $article['topic_views'],
                    'CAT_IMG' => $this->imageUrl($article['news_image']),
                    'POST_DATE' => create_date( $dateformat, $article['post_time'], $timezone),
                    'RFC_POST_DATE' => create_date( 'r', $article['post_time'], $timezone),
                    'L_POSTER' => color_group_colorize_name($article['user_id']),
                    'L_COMMENTS' => 'Comments (' . (int) $article['topic_replies'] . ')',
                    'U_COMMENTS' => $this->root_path . 'viewtopic.' . $this->phpEx . '?topic=' . $topic_id,
                    'U_COMMENT' => $recent_title,
					'U_VIEWS' => $this->root_path . 'topic_view_users.' . $this->phpEx . '?t=' . $topic_id,
					'U_POST_COMMENT' => append_sid('posting.' . $this->phpEx . '?mode=reply&amp;t=' . $topic_id),
					'COUNT_COMMENTS' => (int) $article['topic_replies'],
					'BODY' => $article_body,
					'READ_MORE_LINK' => ($show_abstract && $trimmed) ? '<a href="' . $this->index_file . '?topic_id=' . $topic_id . '" title="' . htmlspecialchars($lang['Read_More'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lang['Read_More'], ENT_QUOTES, 'UTF-8') . '</a>' : ''
                    ) );
	display_portal_news_attachments((int) $article['post_id']);

      }
    }

    if (!is_array($articles) || count($articles) == 0)
    {
	$this->setBlockVariables('no_articles', array(
		'L_NO_NEWS' => $lang['No_articles']));
    }
  }

  /**
  * Fetches articles from the database, and prepares them for display.
  *
  * @param integer (optional) the article id to the article to be displayed.
  *
  * @return void
  *
  * @access private
  */
  function renderArticles( $article_id = 0, $num_items = 0 )
  {
    $this->item_count = 1;
	$article_id = max(0, (int) $article_id);
	$num_items = max(0, min(100, (int) $num_items));

	  $catid = max(0, intval(phpbb_request_scalar($_GET, 'cat_id', 0)));
	  $start = max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0))));
      $this->item_count = $this->data->fetchArticlesCount( $catid );

if( $article_id <= 0 )
    {
      if( $num_items > 0 ) {
        $this->data->setItemCount( $num_items );
      }
      else {
        $this->data->setItemCount( $this->config['news_item_num'] );
      }

      $articles = $this->data->fetchArticles( 0, $catid, $start );
        }
    else
    {
	  $start = max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0))));

      $articles = $this->data->fetchArticle( $article_id );
      $this->renderComments( $article_id, $start );
    }

    $this->prepareArticles( $articles, ($article_id <= 0) );
  }

  /**
  * Prepares the comments for display.
  *
  * @param integer  the article id to fetch the comments for.
  *
  * @return void
  *
  * @access public
  */
  function renderComments( $article_id, $start = 0 )
  {
    global $userdata, $lang, $board_config;
    $trimmed = false;

    $comments = $this->data->fetchPosts( $article_id, $start );
    $this->item_count = $this->data->fetchPostsCount( $article_id );

    if( is_array( $comments ) )
    {
      foreach( $comments as $comment )
      {
        $comment['post_text'] = $this->parseMessage( $comment['post_text'], $comment['bbcode_uid'] );

	$dateformat = ($userdata['user_id'] == ANONYMOUS) ? $board_config['default_dateformat'] : $userdata['user_dateformat'];
	$timezone = ($userdata['user_id'] == ANONYMOUS) ? $board_config['board_timezone'] : $userdata['user_timezone'];

        $this->setBlockVariables( 'comments', array(
                    'L_TITLE' => $this->htmlText($comment['post_subject']),
                    'POST_DATE' => create_date( $dateformat, $comment['post_time'], $timezone),
                    'L_POSTER' => $this->htmlText(($comment['username'] == '') ? $comment['post_username'] : $comment['username']),
                    'BODY' => $comment['post_text']
                    ) );
      }
    }
  }

  function renderTopics( )
  {
    $categories = $this->data->fetchCategories( );

    if( is_array( $categories ) )
    {
      foreach( $categories as $category )
      {
        $this->setBlockVariables( 'categories', array(
                    'ID' => (int) $category['news_id'],
                    'TITLE' => $this->htmlText($category['news_category']),
                    'IMAGE' => $this->imageUrl($category['news_image']),
                    ) );
      }
    }
  }

  function renderDay( $year, $month, $day, $key = '' )
  {
    $this->setBlockVariables( 'arch.year', array(
                  'YEAR' => $year
                  ) );

    $this->setBlockVariables( 'arch.year.month', array(
                'L_MONTH' => date( 'F', mktime( 0,0,0,$month,1,0 ) ),
                'POST_COUNT' => '',
                'MONTH' => $month
                ) );

    $this->setBlockVariables( 'arch.year.month.day', array(
                'L_DAY' => date( 'jS', mktime( 0,0,0,$month,$day,0 ) ),
                'POST_COUNT' => '',
                'DAY' => $day
                ) );

    $articles = $this->data->fetchDay( $day, $month, $year, $key );

    $this->prepareArticles( $articles, true );
  }

  function renderDays( $year, $month )
  {
    $days = $this->data->fetchDays( $month, $year );

    for( $d = 31; $d >= 1; $d-- )
    {
      if( $days[$d] > 0 )
      {
        $this->setBlockVariables( 'arch.year.month.day', array(
                    'L_DAY' => date( 'jS', mktime( 0,0,0,$month,$d,0 ) ),
                    'POST_COUNT' => '('.$days[$d].')',
                    'DAY' => $d
                    ) );
      }
    }
  }

  function renderMonths( $year, $month = 0 )
  {
    $months = $this->data->fetchMonths( $year );

    for( $m = 12; $m >= 1; $m-- )
    {
      if( $months[$m] > 0 )
      {
        $this->setBlockVariables( 'arch.year.month', array(
                    'L_MONTH' => date( 'F', mktime( 0,0,0,$m,1,0 ) ),
                    'POST_COUNT' => '('.$months[$m].')',
                    'MONTH' => $m
                    ) );
        if( $month > 0 && $month == $m )
        {
          $this->renderDays( $year, $m );
        }
      }
    }
  }

  function renderYears( $year = 0, $month = 0 )
  {
    $years = $this->data->fetchYears( );

    if( $years == array( ) ) { 
       return '';
    }

    $render_all = !( $year > 0 && $year >= $years['min'] && $year <= $years['max'] );

    for( $y = $years['max']; $y >= $years['min']; $y-- )
    {
      $this->setBlockVariables( 'arch.year', array(
                  'YEAR' => $y
                  ) );

      if( $render_all || $year == $y )
      {
        $this->renderMonths( $y, $month );
      }
    }
  }

  function renderArchives( $year = 0, $month = 0, $day = 0, $key = '' )
  {
	global $lang;
	$year = max(0, min(2069, (int) $year));
	$month = max(0, min(12, (int) $month));
	$day = max(0, min(31, (int) $day));
	$key = substr((string) $key, 0, 100);

    $this->setBlockVariables( 'arch', array( 'TITLE' => $lang['Archives'] ) );

    if( $day > 0 && $month > 0 && $year > 0 )
    {
      $this->setBlockVariables( 'arch', array( 'CLASS' => 'class="arch-menu"' ) );
      $this->renderDay( $year, $month, $day, $key );
    }
    else
    {
      $this->setBlockVariables( 'arch', array( ) );
      $this->renderYears( $year, $month );
    }
  }
  
  /**
  * Sets up the Sydication Specific template variables.
  *
  * @param integer Overides the number of items to be rendered.
  * @return void
  *
  * @access public
  */
  function renderSyndication( $num_items = 0 ) 
  {
	$this->syndication = true;
    $this->setVariables( array( 
	  'TITLE'       => $this->xmlText($this->config['sitename']),
	  'URL'         => $this->xmlText(phpbb_board_url()),
	  'FORUM_PATH'  => $this->xmlText($this->config['script_path']),
	  'DESC'        => $this->xmlText($this->config['news_rss_desc']),
	  'LANGUAGE'    => $this->xmlText($this->config['news_rss_language']),
	  'COPY_RIGHT'  => $this->xmlText('Copyright 2003, ' . $this->config['sitename']),
	  'EDITOR'      => $this->xmlText($this->config['board_email']),
	  'WEBMASTER'   => $this->xmlText($this->config['board_email']),
	  'TTL'         => max(0, intval($this->config['news_rss_ttl'])),
	  'CATEGORY'    => $this->xmlText($this->config['news_rss_cat']),

	  'GENERATOR'   => 'phpBB2 : CMX News Mod',
      'PUB_DATE'    => date( 'r', mktime( 0,0,0,date('m'),date('d'),date('y') ) )
      ) );
    
    if( $this->config['news_rss_image'] != '' &&
        $this->config['news_rss_image_desc'] != '' ) {
      $this->setBlockVariables( 'image', array(
		'IMAGE'       => $this->xmlText($this->config['news_rss_image']),
		'IMAGE_TITLE' => $this->xmlText($this->config['news_rss_image_desc'])
        ));
    }
    
    $this->item_count = 1;

    $catid = max(0, intval(phpbb_request_scalar($_GET, 'cat_id', 0)));

    if( $num_items > 0 ) {
      $this->data->setItemCount( $num_items );
    }
    else {
      $this->data->setItemCount( $this->config['news_rss_item_count'] );
    }

    $articles = $this->data->fetchArticles( 0, $catid );
 
    $this->prepareArticles( $articles, $this->config['news_rss_show_abstract'] );
  }
  
  /**
  * prepares all the template variables ready for display.
  *
  * @return void
  *
  * @access public
  */
  function render( )
  {
    global $lang;

    // reset the item count.
 
    $this->setVariables( array(
        'L_INDEX' => $lang['Index'],
        'L_CATEGORIES' => $lang['Categories'],
        'L_ARCHIVES' => $lang['Archives']
        ) );

    $news_mode = phpbb_request_scalar($_GET, 'news');
    if ($news_mode == 'categories')
    {
      $this->setVariables( array( 'TITLE' => $lang['News'] . ' ' . $lang['Categories'] ) );
      $this->renderTopics( );
    }
    elseif ($news_mode == 'archives')
    {
      $year = max(0, min(2069, intval(phpbb_request_scalar($_GET, 'year', 0))));
      $month = max(0, min(12, intval(phpbb_request_scalar($_GET, 'month', 0))));
      $day = max(0, min(31, intval(phpbb_request_scalar($_GET, 'day', 0))));
      $key = substr(phpbb_request_scalar($_GET, 'key'), 0, 100);

      $this->setVariables( array( 'TITLE' => $lang['News'] . ' ' . $lang['Archives'] ) );
      $this->renderArchives( $year, $month, $day, $key );
    }
    else
    {
      $topic_id = 0;
      if (phpbb_request_scalar($_GET, 'topic_id') !== '')
      {
        $topic_id = max(0, intval(phpbb_request_scalar($_GET, 'topic_id')));
      }
      elseif (phpbb_request_scalar($_GET, 'news_id') !== '')
      {
        $topic_id = max(0, intval(phpbb_request_scalar($_GET, 'news_id')));
      }

      $this->setVariables( array( 'TITLE' => $lang['News'] . ' ' . $lang['Articles'] ) );
      $this->renderArticles( $topic_id );
    }

    $this->renderPagination( );
  }
  // {{{ trimString( )

  function renderPagination( )
  {
    global $CFG;

    if( $this->item_count > $this->config['news_item_num'] )
    {
      $base_url = $this->config['news_index_file'] . '?news=article';
	  $pagination_topic_id = max(0, intval(phpbb_request_scalar($_GET, 'topic_id', 0)));
	  $pagination_cat_id = max(0, intval(phpbb_request_scalar($_GET, 'cat_id', 0)));
	  if ($pagination_topic_id > 0)
      {
		$base_url .= '&amp;topic_id=' . $pagination_topic_id;
      }
	  if ($pagination_cat_id > 0)
      {
		$base_url .= '&amp;cat_id=' . $pagination_cat_id;
      }

      $this->setBlockVariables( 'pagination', array(
		  'PAGINATION' => generate_pagination($base_url, $this->item_count, $this->config['news_item_num'], max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0)))))
          ));
    }
  }

  /**
  * Trims a given string to the passed length.
  *
  * @access public
  *
  * @param string $source The string to be trimmed..
  * @param integer $length The length the string is to be trimmed to.
  *
  * @return string The resulting trimmed string.
  */
  function trimString( $source, $length )
  {
    $length = intval( $length );

    if( $length <= 0 || strlen( $source ) < $length )
    {
      return $source;
    }

    $result = trim( $source );  // Remove leading and trailing whitespace.
    $result = strip_tags( $result );  // Remove any html or php tags.
    $result = html_entity_decode( $result );  // Convert special entities to characters.

    $result = substr( $result, 0, $length );

    return htmlspecialchars( $result );
  }

  function xmlText($value)
  {
	$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
	return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  // }}}

  // {{{ trimText( )

  /**
  * Post based on a delimeter present in the source text.
  *
  * @access public
  *
  * @param string $source The string to be trimmed.
  * @param string $delim The delimeter used to mark the break in text.
  *
  * @return string The resulting trimmed string.
  */
  function trimText( &$text, $size, &$trimmed )
  {
    $pos = strpos( $text, htmlspecialchars( '<!--break-->' ) );
    if( ($pos !== false) && ($pos < strlen( $text )) ) {
      $trimmed = true;
      return substr( $text, 0, $pos );
    }
    // Breaks up the message by blocks of bbcodes.
    // The message is divided into two parts,
    // 1. text inside a pair of bbcode tags.
    // 2. text not contained inside a pair of bbcode tags.
    $segments = preg_split(
          '#(\[([a-zA-Z]+?).*?\].+?\[/\\2.*?\])#s' ,
          $text, -1,
          PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE );

    foreach( $segments as $segment )
    {
      if( ($segment[1] + strlen($segment[0]) > $size) &&
        ($segment[1] <= $size) )
      // $size fall inside the current block.
      {
        $trimmed = true;
        return substr( $text, 0, $size );
      }
      elseif( $segment[1] > $size )
      // We have gone past the trim point.
      {
        $trimmed = true;
        return substr( $text, 0, $segment[1] );
      }
    }
    $trimmed = false;
    return $text;

  }

  // }}}

  // {{{ decodeBBText( )

  /**
  * Converts BBCode tags to thier html equivelents.
  *
  * @access public
  *
  * @param string $text The body of text to be processed.
  * @param string $bbcode_uid BBCode unique id needed for decoding.
  *
  * @return string The resulting decoded text.
  */
  function decodeBBText( $text, $bbcode_uid )
  {
    if( !isset( $text ) || !isset( $bbcode_uid ) || strlen( $text ) <= 0 )
    {
      return;
    }

    //
    // Parse message and/or sig for BBCode if reqd
    //

    if ( $bbcode_uid != '' )
    {
      $text = ( $this->config['allow_bbcode'] ) ? bbencode_second_pass($text, $bbcode_uid) : preg_replace('/\:[0-9a-z\:]+\]/si', ']', $text);
    }

    $text = make_clickable($text);

    //
    // Parse smilies
    //
    if ( $this->config['allow_smilies'] )
    {
      $text = smilies_pass($text);
      $text = preg_replace( "/images\/smiles/", $this->root_path . $this->config['smilies_path'], $text );
    }

    $text = str_replace("\n", "\n<br />\n", $text);

    if( function_exists( 'acronym_pass' ) )
    {
      $text = acronym_pass( $text );
    }

    return $text;
  }

  // }}

  function parseMessage( $text, $bbcode_uid )
  {
    $text  = $this->decodeBBText( $text, $bbcode_uid );

    // BEGIN CMX News Mod
    // Strip out the <!--break--> delimiter.
    $delim = htmlspecialchars( '<!--break-->' );
    $pos = strpos( $text, $delim );
    if( ($pos !== false) && ($pos < strlen( $text )) ) {
      $text = substr_replace( $text, html_entity_decode($delim), $pos, strlen($delim) );
    }
    // END CMX News Mod
    
    return $text;
  }

  function setVariables( $variables )
  {
    $this->template->assign_vars( $variables );
  }

  function setBlockVariables( $block, $variables )
  {
    $this->template->assign_block_vars( $block, $variables );
  }

  function display( )
  {
    $this->template->pparse( $this->name );
  }

  function clear( )
  {
    $this->template->destroy( );
  }

  function getYear( $timestamp )
  {
    return $this->getDateComp( 'Y', $timestamp );
  }

  function getMonth( $timestamp )
  {
    return $this->getDateComp( 'm', $timestamp );
  }

  function getDay( $timestamp )
  {
    return $this->getDateComp( 'd', $timestamp );
  }

  function getDateComp( $format, $timestamp )
  {
    return gmdate( $format, $timestamp );
  }
}
?>
