<?php

class AI_Image_Ideogram extends AI_Image implements AI_Image_Interface
{
	const JPEG_QUALITY = 85;

	public function generate($prompt, $options = array())
	{
		$apiKey       = Q_Config::expect('AI', 'ideogram', 'key');
		$userCallback = Q::ifset($options, 'callback', null);
		$timeout      = Q::ifset($options, 'timeout', 60);

		$background = Q::ifset($options, 'background', 'none');
		$endpoint = ($background === 'transparent')
			? '/v1/ideogram-v3/generate-transparent'
			: '/v1/ideogram-v3/generate';

		$resolution = $this->resolveResolution($options);

		$postFields = array(
			'prompt'           => $prompt,
			'num_images'       => Q::ifset($options, 'num_images', 1),
			'rendering_speed'  => Q::ifset($options, 'rendering_speed', 'DEFAULT'),
			'magic_prompt'     => Q::ifset($options, 'magic_prompt', 'OFF')
		);

		if ($resolution) {
			$postFields['resolution'] = $resolution;
		}
		if (!empty($options['style_type'])) {
			$postFields['style_type'] = $options['style_type'];
		}
		if (!empty($options['negative_prompt'])) {
			$postFields['negative_prompt'] = $options['negative_prompt'];
		}

		$tmpFiles = array();
		$this->attachReferenceImages($postFields, $options, $tmpFiles);

		return $this->doIdeogramRequest(
			$apiKey, $endpoint, $postFields, $tmpFiles, $timeout, $userCallback
		);
	}

	public function edit($imageData, $maskData, $prompt, $options = array())
	{
		$apiKey       = Q_Config::expect('AI', 'ideogram', 'key');
		$userCallback = Q::ifset($options, 'callback', null);
		$timeout      = Q::ifset($options, 'timeout', 60);

		$postFields = array(
			'prompt'          => $prompt,
			'num_images'      => Q::ifset($options, 'num_images', 1),
			'rendering_speed' => Q::ifset($options, 'rendering_speed', 'DEFAULT'),
			'magic_prompt'    => Q::ifset($options, 'magic_prompt', 'OFF')
		);

		if (!empty($options['style_type'])) {
			$postFields['style_type'] = $options['style_type'];
		}

		$tmpFiles = array();

		// Main image: PNG only if mask exists
		$needsAlpha = !empty($maskData);
		$main = $this->encodeImage($imageData, $needsAlpha);
		$postFields['image'] = new CURLFile($main['path'], $main['mime']);
		$tmpFiles[] = $main['path'];

		// Mask: ALWAYS PNG
		if (!empty($maskData)) {
			$mask = $this->encodeImage($maskData, true);
			$postFields['mask'] = new CURLFile($mask['path'], 'image/png');
			$tmpFiles[] = $mask['path'];
		}

		return $this->doIdeogramRequest(
			$apiKey,
			'/v1/ideogram-v3/edit',
			$postFields,
			$tmpFiles,
			$timeout,
			$userCallback
		);
	}

	/* ====================== helpers ====================== */

	protected function resolveResolution($options)
	{
		if (!empty($options['size'])
			&& preg_match('/^(\d+)x(\d+)$/', $options['size'], $m)) {
			return "{$m[1]}x{$m[2]}";
		}

		$resolution = Q::ifset($options, 'resolution', null);
		if ($resolution) return $resolution;

		$w = Q::ifset($options, 'width', 1024);
		$h = Q::ifset($options, 'height', 1024);
		return "{$w}x{$h}";
	}

	protected function encodeImage($binary, $forcePng = false)
	{
		$raw = Q_Utils::toRawBinary($binary);
		if ($raw === false) return null;

		$img = @imagecreatefromstring($raw);
		if (!$img) return null;

		$hasAlpha = $forcePng || $this->imageHasAlpha($img);

		if ($hasAlpha) {
			$tmp = tempnam(sys_get_temp_dir(), 'ideo_') . '.png';
			imagepng($img, $tmp);
			imagedestroy($img);
			return array('path' => $tmp, 'mime' => 'image/png');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'ideo_') . '.jpg';
		imagejpeg($img, $tmp, self::JPEG_QUALITY);
		imagedestroy($img);
		return array('path' => $tmp, 'mime' => 'image/jpeg');
	}

	protected function imageHasAlpha($img)
	{
		if (!imageistruecolor($img)) return false;
		return imagecolortransparent($img) >= 0;
	}

	protected function attachReferenceImages(&$postFields, $options, &$tmpFiles)
	{
		if (empty($options['character_reference_images'])
			|| !is_array($options['character_reference_images'])) {
			return;
		}

		foreach ($options['character_reference_images'] as $i => $binary) {
			$img = $this->encodeImage($binary, false);
			if (!$img) continue;

			$postFields["character_reference_images[$i]"]
				= new CURLFile($img['path'], $img['mime']);
			$tmpFiles[] = $img['path'];

			// Optional mask → PNG
			if (!empty($options['character_reference_images_mask'][$i])) {
				$mask = $this->encodeImage(
					$options['character_reference_images_mask'][$i],
					true
				);
				if ($mask) {
					$postFields["character_reference_images_mask[$i]"]
						= new CURLFile($mask['path'], 'image/png');
					$tmpFiles[] = $mask['path'];
				}
			}
		}
	}

	protected function doIdeogramRequest(
		$apiKey, $endpoint, &$postFields, &$tmpFiles, $timeout, $userCallback
	) {
		$result = array('data' => null, 'format' => 'png', 'error' => null);

		$callback = function ($info, $response) use (&$result, $userCallback, &$tmpFiles) {
			foreach ($tmpFiles as $tmp) {
				@unlink($tmp);
			}

			$httpCode = Q::ifset($info, 'http_code', 0);
			if ($httpCode >= 200 && $httpCode < 300) {
				$json = json_decode($response, true);
				if (!empty($json['data'][0]['url'])) {
					$binary = @file_get_contents($json['data'][0]['url']);
					if ($binary !== false) {
						$result['data'] = $binary;
						return;
					}
				}
				$result['error'] = $json ?: 'Invalid Ideogram payload';
			} else {
				$result['error'] = $response;
			}

			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $result);
			}
		};

		return Q_Utils::post(
			'https://api.ideogram.ai' . $endpoint,
			$postFields,
			null,
			null,
			array(
				"Api-Key: $apiKey",
				"Content-Type: multipart/form-data"
			),
			$timeout,
			$callback
		);
	}
}
