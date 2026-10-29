<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_listing.php';

// Truncate bytes only at a complete UTF-8 character boundary.
function pafiledb_search_input($value, $bytes)
{
 if (!is_string($value) || preg_match('//u', $value) !== 1) { throw new InvalidArgumentException('Invalid search text'); }
 $value = (string)substr(trim($value), 0, $bytes);
 while ($value !== '' && preg_match('//u', $value) !== 1) { $value = (string)substr($value, 0, -1); }
 return $value;
}
function pafiledb_search_categories($module, $category)
{
 $allowed = pafiledb_toplist_categories($module);
 if (!$category) { return $allowed; }
 if (!isset($module->cat_rowset[$category])) { return array(); }
 $scoped = array();
 foreach ($allowed as $id)
 {
  $cursor = $id; $seen = array();
  while ($cursor && isset($module->cat_rowset[$cursor]) && !isset($seen[$cursor]))
  {
   if ($cursor === $category) { $scoped[] = $id; break; }
   $seen[$cursor] = true; $cursor = (int)$module->cat_rowset[$cursor]['cat_parent'];
  }
 }
 return $scoped;
}
function pafiledb_search_ids($value)
{
 if (is_string($value)) { $value = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY); }
 if (!is_array($value)) { return array(); }
 $ids = array();
 foreach (array_slice($value, 0, 12000) as $id)
 {
  if ((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id) && (float)$id <= 2147483647)
  { $ids[(int)$id] = (int)$id; }
 }
 return array_values($ids);
}
function pafiledb_search_author_sql($database, $author)
{
 if ($author === '') { return '1=1'; }
 $pattern = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $author);
 $pattern = str_replace('*', '%', $pattern);
 return 'EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' author WHERE author.user_id=f.user_id AND author.user_id>0'
  . " AND author.username LIKE '" . $database->sql_escape($pattern) . "' ESCAPE '!')";
}
function pafiledb_search_refresh_ids($database, $categories, $ids, $author = '')
{
 $ids = pafiledb_search_ids($ids);
 if (!$ids) { return array(); }
 $rows = pafiledb_listing_rows($database, 'SELECT f.file_id FROM ' . PA_FILES_TABLE . ' f WHERE f.file_approved=1 AND '
  . pafiledb_toplist_scope_sql($categories) . ' AND f.file_id IN (' . implode(',', $ids) . ') AND '
  . pafiledb_search_author_sql($database, $author) . ' ORDER BY f.file_id');
 return array_map(function($row) { return (int)$row['file_id']; }, $rows);
}
function pafiledb_search_page_sql($categories, $ids, $sort, $direction, $start, $per_page)
{
 $ids = pafiledb_search_ids($ids); $options = pafiledb_listing_options($sort, $direction, $start, $per_page);
 return 'SELECT f.*,COALESCE(f.file_dls,0) AS file_dls,u.username,u.user_id AS current_submitter_id,c.cat_id,c.cat_name,'
  . ' (SELECT AVG(v.rate_point) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS rating,'
  . ' (SELECT COUNT(*) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS total_votes,'
  . ' (SELECT COUNT(*) FROM ' . PA_COMMENTS_TABLE . ' cm WHERE cm.file_id=f.file_id) AS total_comments'
  . ' FROM ' . PA_FILES_TABLE . ' f INNER JOIN ' . PA_CATEGORY_TABLE . ' c ON c.cat_id=f.file_catid'
  . ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=f.user_id'
  . ' WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories)
  . ' AND ' . ($ids ? 'f.file_id IN (' . implode(',', $ids) . ')' : '1=0')
  . ' ORDER BY ' . $options['sort'] . ' ' . $options['direction'] . ',f.file_id ' . $options['direction']
  . ' LIMIT ' . $options['start'] . ',' . $options['per_page'];
}

function pafiledb_search_match_sql($database, $categories, $comment_categories, $words, $all_words, $author, $include_comments)
{
 if (!is_array($words)) { throw new InvalidArgumentException('Invalid search terms'); }
 $expression = null; $operator = $all_words ? 'and' : 'or';
 $comment_scope = pafiledb_toplist_scope_sql($comment_categories);
 foreach (array_slice($words, 0, 50) as $word)
 {
  if (!is_string($word)) { throw new InvalidArgumentException('Invalid search term'); }
  if ($word === '') { continue; }
  if (in_array($word, array('and', 'or', 'not'), true)) { $operator = $word; continue; }
  $pattern = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $word);
  $pattern = '%' . str_replace('*', '%', $pattern) . '%';
  $literal = "'" . $database->sql_escape($pattern) . "' ESCAPE '!'";
  $predicate = "(COALESCE(f.file_name,'') LIKE " . $literal . " OR COALESCE(f.file_creator,'') LIKE " . $literal
   . " OR COALESCE(f.file_desc,'') LIKE " . $literal . " OR COALESCE(f.file_longdesc,'') LIKE " . $literal . ')';
  if ($include_comments && $comment_categories)
  {
   // An uncorrelated set of matching file identities avoids duplicate rows.
   // Apply the candidate cap only after the complete Boolean expression.
   $predicate = '(' . $predicate . ' OR (' . $comment_scope . ' AND f.file_id IN (SELECT cm.file_id FROM ' . PA_COMMENTS_TABLE
    . ' cm WHERE cm.comments_title LIKE ' . $literal . ' OR cm.comments_text LIKE ' . $literal . ')))';
  }
  if ($expression === null) { $expression = $operator === 'not' ? '(NOT ' . $predicate . ')' : $predicate; }
  else { $expression = '(' . $expression . ($operator === 'not' ? ' AND NOT ' : ($operator === 'and' ? ' AND ' : ' OR ')) . $predicate . ')'; }
 }
 return 'SELECT f.file_id FROM ' . PA_FILES_TABLE . ' f WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories)
  . ' AND ' . pafiledb_search_author_sql($database, $author) . ' AND ' . ($expression === null ? '1=0' : $expression)
  . ' ORDER BY f.file_id LIMIT 12000';
}

