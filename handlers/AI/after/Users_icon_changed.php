<?php
/**
 * When a user's icon changes, optionally produce a background-removed cutout
 * via AI_Image::promptFace() and save it as cutout.png alongside the icon sizes.
 *
 * Enable with:  "AI": { "image": { "autoFaceIcon": true } }
 *
 * The cutout is saved once per icon timestamp directory. If cutout.png already
 * exists the work is skipped, so re-fires and retries are safe.
 *
 * @module AI
 */
function AI_after_Users_icon_changed($params)
{
	if (!Q_Config::get('AI', 'image', 'autoFaceIcon', false)) {
		return;
	}

	extract($params);
	/**
	 * @var Users_User $user
	 * @var string $path
	 * @var string $subpath
	 */

	// Resolve the icon directory on disk.
	$iconDir = Q::realPath("$path/$subpath");
	if (!$iconDir or !is_dir($iconDir)) {
		return;
	}

	// Already processed — skip to avoid loops and duplicate work.
	$cutoutFile = $iconDir . DS . 'cutout.png';
	if (file_exists($cutoutFile)) {
		return;
	}

	// Pick the largest available icon size as the input.
	$best = null;
	$bestSize = 0;
	foreach (glob($iconDir . DS . '*.png') as $file) {
		$name = pathinfo($file, PATHINFO_FILENAME);
		if (!is_numeric($name)) {
			continue; // skip non-size files
		}
		if ((int)$name > $bestSize) {
			$bestSize = (int)$name;
			$best = $file;
		}
	}
	if (!$best) {
		return;
	}

	$imageData = file_get_contents($best);
	if (!$imageData) {
		return;
	}

	try {
		$adapter = Q_Config::get('AI', 'image', 'adapter', 'openai');
		$ai = AI_Image::create($adapter);
		$result = $ai->generate(
			AI_Image::promptFace(),
			array(
				'images' => array($imageData),
				'format' => 'png',
				'model' => Q_Config::get('AI', 'image', 'model', 'gpt-image-2.5-sunburst'),
				'size' => Q_Config::get('AI', 'image', 'faceSize', '1024x1024'),
				'timeout' => (int)Q_Config::get('AI', 'image', 'timeout', 60)
			)
		);
		if (!empty($result['data'])) {
			file_put_contents($cutoutFile, $result['data']);
		}
	} catch (Exception $e) {
		Q::log("AI/after/Users_icon: promptFace failed for {$user->id}: "
			. $e->getMessage(), 'warnings');
	}
}