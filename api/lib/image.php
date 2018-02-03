<?php

include_once(__DIR__ . "/smart_resize_image.function.php");

function resizeImg($base64_string, $resizeSettings) {
    // split the string -> prefix & data
    list($prefix, $imgdata) = explode(',', $base64_string);
	$imgdata = base64_decode($imgdata);

	list($oldWidth, $oldHeight, $type) = getimagesizefromstring($imgdata);

	if (isset($resizeSettings['cropFactor'])) {
		// if height > width -> resize to 400px width & crop
		$maxY = $oldHeight > $oldWidth
			? 100000
			: $resizeSettings['maxY'];
		
		// resize
		$newImage = smart_resize_image(null, $imgdata, $resizeSettings['maxX'], $maxY, true, 'return');
		
		// get width/height of new image
		$width = imagesx($newImage);
		$height = imagesy($newImage);
		$maxheight = $width * $resizeSettings['cropFactor'];
		
		// crop if needed
		if ($height > $maxheight) {
			$newImage = imagecrop($newImage, array(
				'x' => 0,
				'y' => 0,//(int)(($height-$maxheight)/2),
				'width' => $width,
				'height' => $maxheight
			));
		}
	} else {
		// resize
		$newImage = smart_resize_image(null, $imgdata, $resizeSettings['maxX'], $resizeSettings['maxY'], true, 'return');
	}
	
	// get new image string
	ob_start();
	switch ($type) {
		case IMAGETYPE_GIF:
			imagegif($newImage);
			break;
		case IMAGETYPE_JPEG:
			imagejpeg($newImage, null, $resizeSettings['quality']);
			break;
		case IMAGETYPE_PNG:
			$quality = 9 - (int)((0.9*$resizeSettings['quality'])/10.0);
			imagepng($newImage, null, $quality);
			break;
		default:
			return '';
    }
	$newimgData = ob_get_contents();
	ob_end_clean();

	// return new image (prefix + resized data)
	return $prefix.','.base64_encode($newimgData);
}

?>
