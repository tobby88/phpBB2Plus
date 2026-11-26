<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class feedback
{
	private $access;
	private $db;
	private $user;
	private $config;
	private $renderer;
	private $prefix;
	private $http;

	public function __construct($access, $db, $user, $config, $renderer, $prefix, $http)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->renderer = $renderer;
		$this->prefix = $prefix;
		$this->http = $http;
	}

	public function can($f, $operation)
	{
		if (!$f['approved'] || !empty($this->user->data['is_bot'])) { return false; }
		$cat = (int) $f['cat_id'];
		$c = $this->access->categories()[$cat];
		if ($operation === 'rate') { return $c['allow_ratings'] && $this->access->allows($cat,'rate'); }
		return $operation === 'comment' && $c['allow_comments'] && $this->access->allows($cat,'post_comment');
	}

	public function can_delete($f, $c)
	{
		return !empty($this->user->data['is_registered']) && $f['approved'] && ((int) $c['file_id'] === (int) $f['file_id']) &&
			($this->access->allows((int) $f['cat_id'],'mod') || ((int) $this->user->data['user_id'] === (int) $c['owner_id'] &&
			$this->access->allows((int) $f['cat_id'],'delete_comment')));
	}

	public function comment($id, $file)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'comments WHERE comment_id=' . (int) $id . ' AND file_id=' . (int) $file);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function render($xml)
	{
		$rules = array();
		if (!(int) $this->access->setting('allow_comment_images', 1)) { $rules['IMG'] = $this->access->setting('no_comment_image_message', ''); }
		if (!(int) $this->access->setting('allow_comment_links', 1))
		{
			$rules['URL'] = $rules['EMAIL'] = $this->access->setting('no_comment_link_message', '');
		}
		$doc = null;
		foreach ($rules as $tag => $label)
		{
			if (strpos($xml, '<' . $tag) === false) { continue; }
			if ($doc === null)
			{
				$doc = new \DOMDocument();
				if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype) { return ''; }
			}
			$nodes = $doc->getElementsByTagName($tag);
			while ($nodes->length)
			{
				$node = $nodes->item(0);
				$node->parentNode->replaceChild($doc->createTextNode(access::plain($label)), $node);
			}
		}
		return $this->renderer->render($doc ? $doc->saveXML($doc->documentElement) : $xml);
	}

	public function comment_options()
	{
		return array(
			'limit' => max(0, min(65535, (int) $this->access->setting('max_comment_chars', 5000))),
			'bbcode' => (int) $this->access->setting('allow_bbcode', 1) && $this->config['allow_bbcode'] && $this->user->optionget('bbcode'),
			'smilies' => (int) $this->access->setting('allow_smilies', 1) && $this->config['allow_smilies'] && $this->user->optionget('smilies'),
			'links' => (bool) $this->access->setting('allow_comment_links', 1),
			'images' => (bool) $this->access->setting('allow_comment_images', 1),
			'font_size' => (int) $this->config['max_post_font_size'],
		);
	}

	public function prepare_comment($title, $body)
	{
		$options = $this->comment_options();
		if ($body === '' || utf8_strlen($body) > $options['limit'] || strlen($body) > 65535 || strlen($title) > 65535)
		{
			throw new \DomainException('PLUS_DL_BAD_COMMENT');
		}
		$uid = $bits = '';
		$flags = 0;
		$text = utf8_htmlspecialchars($body);
		generate_text_for_storage($text, $uid, $bits, $flags, $options['bbcode'], $options['links'], $options['smilies'],
			$options['images'], false, true, $options['links']);
		return array('title' => utf8_encode_ucr(utf8_htmlspecialchars($title)), 'body' => $text);
	}

	private function vote($id, $owner, $points)
	{
		if ($points < 1 || $points > 10) { throw new \DomainException('PLUS_DL_BAD_VOTE'); }
		$table = $this->prefix . 'votes';
		$ip = $this->user->ip;
		$actor = $owner ? "owner_id=$owner" : "ip_address='" . $this->db->sql_escape($ip) . "'";
		$result = $this->db->sql_query_limit("SELECT row_id FROM $table WHERE file_id=$id AND $actor", 1);
		$already = $this->db->sql_fetchfield('row_id') !== false;
		$this->db->sql_freeresult($result);
		if ($already) { throw new \DomainException('PLUS_DL_ALREADY_VOTED'); }
		$family = client_family::observed($this->http->headers->get('User-Agent',''));
		$row = $family + array('file_id'=>$id,'owner_id'=>$owner,'points'=>$points,'ip_address'=>$ip,'source_json'=>json_encode(array(
			'voter_os'=>$family['client_os'],'voter_browser'=>$family['client_browser'])));
		$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$row));
	}

	public function publish($id, $operation, $nonce, $title, $body, $points)
	{
		return $this->access->write($id, function ($f) use ($operation,$nonce,$title,$body,$points) {
			if (!$this->can($f,$operation)) { throw new NotFoundHttpException(); }
			$id = (int) $f['file_id'];
			$owner = !empty($this->user->data['is_registered']) ? (int) $this->user->data['user_id'] : 0;
			if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) { throw new \DomainException('FORM_INVALID'); }
			$key = hash_hmac('sha256', $this->user->session_id . ':' . $owner . ':' . $id . ':' . $operation . ':' . $nonce, $this->config['plus_download_media_key']);
			$digest = hash('sha256', json_encode(array($title,$body,$points)));
			$table = $this->prefix . 'receipts';
			$result = $this->db->sql_query("SELECT payload_hash,item_id FROM $table WHERE request_key='$key'");
			$existing = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($existing)
			{
				if ($existing['payload_hash'] !== $digest) { throw new \DomainException('FORM_INVALID'); }
				return (int) $existing['item_id'];
			}
			$item = 0;
			if ($operation === 'rate') { $this->vote($id,$owner,$points); }
			else
			{
				$row = $this->prepare_comment($title, $body) + array('file_id'=>$id,'owner_id'=>$owner,'posted_at'=>time(),'source_json'=>'{}');
				$row += search_text::comment($row);
				$this->db->sql_query('INSERT INTO ' . $this->prefix . 'comments ' . $this->db->sql_build_array('INSERT',$row));
				$item = (int) $this->db->sql_nextid();
			}
			$row = array('request_key'=>$key,'payload_hash'=>$digest,'file_id'=>$id,'item_id'=>$item,'created_at'=>time());
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$row));
			return $item;
		});
	}

	public function remove($file, $comment, $check)
	{
		$this->access->write($file, function ($f) use ($comment,$check) {
			$c = $this->comment($comment,$f['file_id']);
			if (!$this->can_delete($f,$c)) { throw new NotFoundHttpException(); }
			if (!hash_equals(self::revision($c), $check)) { throw new \DomainException('FORM_INVALID'); }
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'comments WHERE comment_id=' . (int) $c['comment_id'] . ' AND file_id=' . (int) $f['file_id']);
		});
	}

	static public function revision($c) { return hash('sha256', json_encode(array($c['file_id'],$c['comment_id'],$c['owner_id'],$c['title'],$c['body']))); }
}
