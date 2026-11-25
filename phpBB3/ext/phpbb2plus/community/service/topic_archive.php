<?php
namespace phpbb2plus\community\service;

class topic_archive
{
	public const MAX_BYTES = 67108864;

	private static function put($stream, $data, &$bytes, $limit)
	{
		$size = strlen($data);
		if ($size > $limit - $bytes) { throw new \LengthException(); }
		if (fwrite($stream, $data) !== $size) { throw new \RuntimeException('Cannot write text archive'); }
		$bytes += $size;
	}

	public static function build($topic, $rows, $language, $limit = self::MAX_BYTES)
	{
		$stream = fopen('php://temp/maxmemory:2097152', 'w+b');
		if (!$stream) { throw new \RuntimeException('Cannot open text archive'); }
		try
		{
			$title = topic_text::line(censor_text($topic['topic_title']));
			$forum = topic_text::line($topic['forum_name']);
			$bytes = 0;
			$header = $language->lang('FORUM') . ":\t" . $forum . "\r\n" . $language->lang('TOPIC') . ":\t" . $title . "\r\n" .
				str_repeat('-', min(200, utf8_strlen($title)) + 8) . "\r\n";
			self::put($stream, $header, $bytes, $limit);
			$found = false;
			foreach ($rows as $post)
			{
				if (!empty($post['REMOVED'])) { $text = $language->lang('PLUS_REMOVED_POSTS', 1); }
				elseif (!empty($post['IGNORED'])) { $text = $language->lang('PLUS_PRINT_IGNORED'); }
				else { $text = topic_text::line($post['AUTHOR']) . ":\r\n" . topic_text::body($post['BODY']); }
				self::put($stream, "\r\n" . $text . "\r\n", $bytes, $limit);
				$found = true;
			}
			if (!$found) { throw new \UnderflowException('No readable posts'); }
			if (!fflush($stream) || ftell($stream) !== $bytes || fseek($stream, 0) !== 0)
			{ throw new \RuntimeException('Incomplete text archive'); }
			return array($stream, $bytes);
		}
		catch (\Throwable $error) { fclose($stream); throw $error; }
	}
}
