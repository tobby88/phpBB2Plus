<?php
// Real source/target files in the caller's freshly owned scratch directory.
$alpha_cases = 0;
$alpha_kinds = array('png-127','png-63','png-1','png-126','png-0','gif-transparent','gif-same-rgb','gif-opaque','jpeg-opaque');
foreach (array(array(480,320),array(320,480)) as $dimensions) {
	foreach ($alpha_kinds as $kind) {
		$gif = strpos($kind,'gif-') === 0;$jpeg = $kind === 'jpeg-opaque';
		$alpha = strpos($kind,'png-') === 0 ? (int) substr($kind,4) : ($gif && $kind !== 'gif-opaque' ? 127 : 0);
		$source_file = tempnam($image_fixture_directory,'phpbb-alpha-source-');$target_file = tempnam($image_fixture_directory,'phpbb-alpha-target-');
		try {
			$image = $gif ? imagecreate($dimensions[0],$dimensions[1]) : imagecreatetruecolor($dimensions[0],$dimensions[1]);
			imagealphablending($image,false);imagesavealpha($image,true);
			// Index zero is deliberately opaque. The transparent GIF index is
			// separate even when its RGB matches a visible foreground color.
			$opaque = imagecolorallocatealpha($image,20,80,150,0);
			$background = imagecolorallocatealpha($image,20,80,150,$alpha);
			imagefill($image,0,0,$background);
			if ($gif && $alpha === 127) { imagecolortransparent($image,$background); }
			$center_rgb = $kind === 'gif-same-rgb' ? array(20,80,150) : array(200,40,20);
			$center = $kind === 'gif-same-rgb' ? $opaque : imagecolorallocatealpha($image,$center_rgb[0],$center_rgb[1],$center_rgb[2],0);
			imagefilledrectangle($image,(int)($dimensions[0]/4),(int)($dimensions[1]/4),(int)($dimensions[0]*3/4),(int)($dimensions[1]*3/4),$center);
			$saved = $gif ? imagegif($image,$source_file) : ($jpeg ? imagejpeg($image,$source_file,95) : imagepng($image,$source_file));phpbb_gd_release($image);
			image_processing_assert($saved,'write actual synthetic alpha/opaque source');$original_hash = hash_file('sha256',$source_file);
			$read = $gif ? 'imagecreatefromgif' : ($jpeg ? 'imagecreatefromjpeg' : 'imagecreatefrompng');
			$loaded = $read($source_file);$original = imagecolorsforindex($loaded,imagecolorat($loaded,10,10));phpbb_gd_release($loaded);
			image_processing_assert($original['alpha'] === $alpha,'actual encoded source alpha matches fixture');
			foreach (array(0,1) as $gd2) {
				$attach_config['use_gd2']=$gd2;
				image_processing_assert(create_thumbnail($source_file,$target_file,$gif?'image/gif':($jpeg?'image/jpeg':'image/png')),'publish actual alpha thumbnail: '.$kind.'/'.$gd2);
				$loaded=$read($target_file);$shape=getimagesize($target_file);
				$corner=imagecolorsforindex($loaded,imagecolorat($loaded,10,10));$center=imagecolorsforindex($loaded,imagecolorat($loaded,(int)($shape[0]/2),(int)($shape[1]/2)));phpbb_gd_release($loaded);
				image_processing_assert($corner['alpha'] === $original['alpha'],'preserve actual corner alpha: '.$kind.'/'.$gd2.' '.json_encode(array($original,$corner,$center)));
				image_processing_assert($center['alpha'] === 0,'same-RGB opaque foreground must never become transparent: '.$kind.'/'.$gd2);
				foreach (array('red','green','blue') as $channel=>$name) { image_processing_assert(abs($center[$name]-$center_rgb[$channel]) <= ($gif || $jpeg ? 8 : 0),'preserve opaque foreground color: '.$kind.'/'.$gd2); }
				// Old libgd can round one 8-bit RGB channel down by one while
				// retaining exact alpha. Compositing against black is far larger.
				if ($alpha < 127) { foreach (array('red','green','blue') as $name) { image_processing_assert(abs($corner[$name]-$original[$name]) <= ($gif || $jpeg ? 8 : 1),'no black compositing of visible source color: '.$kind.'/'.$gd2.' '.json_encode(array($original,$corner))); } }
				image_processing_assert(hash_file('sha256',$source_file)===$original_hash && glob($image_fixture_directory.'/.phpbb-thumb-*')===array(),'preserve source bytes and clean only own stage');$alpha_cases++;
			}
		} finally { unlink($source_file);unlink($target_file); }
	}
}
echo 'Attachment thumbnail alpha: '.$alpha_cases." actual PNG/GIF/JPEG files PASS; both resize modes, partial alpha, opaque same-RGB foreground, colors, source bytes and owned cleanup.\n";
