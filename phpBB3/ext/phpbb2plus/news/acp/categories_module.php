<?php
namespace phpbb2plus\news\acp;

use phpbb\request\request_interface;

class categories_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	private $db;
	private $language;
	private $table;
	private $artwork;

	private function row($id, $lock = false)
	{
		$result = $this->db->sql_query('SELECT category_id,name,image,active FROM ' . $this->table . ' WHERE category_id=' . (int) $id . ($lock ? ' FOR UPDATE' : ''));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function revision($row)
	{
		return hash('sha256',serialize(array((int) $row['category_id'],$row['name'],$row['image'],(int) $row['active'])));
	}

	private function images($directory, $current)
	{
		$names = $files = array();
		$result = $this->db->sql_query('SELECT asset,label FROM ' . $this->artwork);
		while ($r = $this->db->sql_fetchrow($result)) { $names[$r['asset']] = $r['label']; }
		$this->db->sql_freeresult($result);
		if (!is_dir($directory)) { return $files; }
		$types = array('gif'=>IMAGETYPE_GIF,'jpg'=>IMAGETYPE_JPEG,'jpeg'=>IMAGETYPE_JPEG,'png'=>IMAGETYPE_PNG);
		foreach (new \DirectoryIterator($directory) as $file)
		{
			$name = $file->getFilename();
			if (!$file->isFile() || $file->isLink() || !preg_match('/^[\pL\pN _-]+\.(gif|jpe?g|png)$/iuD',$name,$m)) { continue; }
			$info = @getimagesize($file->getPathname());
			if (!$info || $info[2] !== $types[strtolower($m[1])]) { continue; }
			$digest = hash_file('sha256',$file->getPathname());
			// The builder and converter can supply identical copies under different names.
			if (!isset($files[$digest]) || $current === $name || ($files[$digest]['name'] !== $current && isset($names[$name])))
			{
				$files[$digest] = array('name'=>$name,'label'=>$names[$name] ?? utf8_htmlspecialchars($name));
			}
		}
		$images = array_column($files,'label','name');
		asort($images,SORT_NATURAL | SORT_FLAG_CASE);
		return $images;
	}

	private function save($id, $revision, $values, $remove)
	{
		$this->db->sql_transaction('begin');
		$changed = true;
		try
		{
			if ($id)
			{
				$current = $this->row($id,true);
				$same = $current && !$remove && $current['name'] === $values['name'] && $current['image'] === $values['image'] &&
					(int) $current['active'] === $values['active'];
				if (!$current || (!$same && !hash_equals($this->revision($current),$revision))) { throw new \DomainException('PLUS_NEWS_CONFLICT'); }
				if ($remove)
				{
					$this->db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET plus_news_cat=0 WHERE plus_news_cat=' . $id);
					$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE category_id=' . $id);
				}
				elseif (!$same) { $this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE',$values) . ' WHERE category_id=' . $id); }
				else { $changed = false; }
			}
			else
			{
				// Serialise additions so a repeated form submission creates a single category.
				$result = $this->db->sql_query("SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='plus_news_publish' FOR UPDATE");
				$this->db->sql_freeresult($result);
				$result = $this->db->sql_query_limit('SELECT category_id FROM ' . $this->table . ' WHERE ' . $this->db->sql_build_array('SELECT',$values),1);
				$exists = (bool) $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);
				if (!$exists) { $this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT',$values)); }
				else { $changed = false; }
			}
			$this->db->sql_transaction('commit');
			return $changed;
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $table_prefix, $phpbb_root_path;
		$this->db = $phpbb_container->get('dbal.conn');
		$this->language = $phpbb_container->get('language');
		$this->language->add_lang('news','phpbb2plus/news');
		$this->table = $table_prefix . 'plus_news_cats';
		$this->artwork = $table_prefix . 'plus_news_images';
		$request = $phpbb_container->get('request');
		$template = $phpbb_container->get('template');
		$this->tpl_name = 'acp_plus_news';
		$this->page_title = 'ACP_PLUS_NEWS_CATEGORIES';
		$id = max(0,$request->variable('cat',0,false,request_interface::GET));
		$new = $request->variable('new',0,false,request_interface::GET) === 1;
		$template->assign_vars(array('U_LIST'=>$this->u_action,'U_NEW'=>$this->u_action . '&amp;new=1'));
		if (!$id && !$new)
		{
			$result = $this->db->sql_query('SELECT category_id,name,active FROM ' . $this->table . ' ORDER BY active DESC,name,category_id');
			while ($r = $this->db->sql_fetchrow($result))
			{
				$template->assign_block_vars('categories',array('NAME'=>$r['name'] ?: $this->language->lang('PLUS_NEWS_RETIRED',(int) $r['category_id']),
					'ACTIVE'=>(bool) $r['active'],'U_EDIT'=>$this->u_action . '&amp;cat=' . (int) $r['category_id']));
			}
			$this->db->sql_freeresult($result);
			return;
		}

		$row = $id ? $this->row($id) : array('category_id'=>0,'name'=>'','image'=>'','active'=>1);
		if (!$row) { trigger_error('PLUS_NEWS_UNKNOWN',E_USER_WARNING); }
		$url = $this->u_action . ($id ? '&amp;cat=' . $id : '&amp;new=1');
		$images = $this->images($phpbb_root_path . 'images/plusnews',$row['image']);
		$form = 'phpbb2plus_news_category_' . $id;
		add_form_key($form);
		if ($request->is_set_post('cancel')) { redirect($this->u_action); }
		$remove = $id && $request->is_set_post('remove');
		if ($remove || $request->is_set_post('save'))
		{
			$previous = $request->variable('revision','',false,request_interface::POST);
			if (!$remove || !confirm_box(true))
			{
				if (!check_form_key($form)) { trigger_error('FORM_INVALID',E_USER_WARNING); }
				if ($remove)
				{
					confirm_box(false,'PLUS_NEWS_DELETE',build_hidden_fields(array('remove'=>1,'revision'=>$previous)));
					return;
				}
			}
			$name = trim($request->variable('category_name','',true,request_interface::POST));
			$image = $request->variable('category_image','',true,request_interface::POST);
			$active = $request->is_set_post('active') ? 1 : 0;
			if (!$remove && ($name === '' || utf8_strlen($name) > 255 || ($image !== '' && !isset($images[$image]) && $image !== $row['image'])))
			{
				trigger_error('PLUS_NEWS_INVALID_CATEGORY',E_USER_WARNING);
			}
			try { $changed = $this->save($id,$previous,array('name'=>$name,'image'=>$image,'active'=>$active),$remove); }
			catch (\DomainException $error) { trigger_error($error->getMessage(),E_USER_WARNING); }
			if ($changed)
			{
				$user = $phpbb_container->get('user');
				$phpbb_container->get('log')->add('admin',$user->data['user_id'],$user->ip,
					$remove ? 'LOG_PLUS_NEWS_DELETED' : ($id ? 'LOG_PLUS_NEWS_CHANGED' : 'LOG_PLUS_NEWS_CREATED'),false,array($remove ? $row['name'] : $name));
			}
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		$template->assign_vars(array('S_EDITOR'=>true,'S_EXISTING'=>(bool) $id,'U_SAVE'=>$url,'CATEGORY_NAME'=>$row['name'],
			'ACTIVE'=>(bool) $row['active'],'REVISION'=>$this->revision($row),'S_NO_IMAGE'=>!$row['image']));
		if ($row['image'] !== '' && !isset($images[$row['image']]))
		{
			$template->assign_block_vars('images',array('NAME'=>utf8_htmlspecialchars($row['image']),'LABEL'=>utf8_htmlspecialchars($row['image']),'SELECTED'=>true,'URL'=>''));
		}
		foreach ($images as $name=>$label)
		{
			$template->assign_block_vars('images',array('NAME'=>utf8_htmlspecialchars($name),'LABEL'=>$label,'SELECTED'=>$row['image'] === $name,
				'URL'=>$phpbb_container->get('path_helper')->get_web_root_path() . 'images/plusnews/' . rawurlencode($name)));
		}
	}
}
