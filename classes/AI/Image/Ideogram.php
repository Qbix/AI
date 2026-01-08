<?php

class AI_Image_Ideogram extends AI_Image implements AI_Image_Interface
{
	public function generate($prompt, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'ideogram', 'key');
		$userCallback = Q::ifset($options, 'callback', null);
		$timeout = Q::ifset($options, 'timeout', 60);

		// Decide transparent or normal endpoint
		$background = Q::ifset($options, 'background', 'none');
		$endpoint = ($background === 'transparent')
			? '/v1/ideogram-v3/generate-transparent'
			: '/v1/ideogram-v3/generate';

		// Resolution / aspect ratio
		if (!empty($options['size']) && preg_match('/^(\d+)x(\d+)$/', $options['size'], $m)) {
			$resolution = "{$m[1]}x{$m[2]}";
		} else {
			$resolution = Q::ifset($options, 'resolution', null);
			if (!$resolution) {
				$w = Q::ifset($options, 'width', 1024);
				$h = Q::ifset($options, 'height', 1024);
				$resolution = "{$w}x{$h}";
			}
		}

		// Build form fields
		$postFields = array(
			'prompt' => $prompt,
			'num_images' => Q::ifset($options, 'num_images', 1),
			'rendering_speed' => Q::ifset($options, 'rendering_speed', 'DEFAULT'),
			'magic_prompt' => Q::ifset($options, 'magic_prompt', 'OFF'),
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

		// Attach optional file lists
		$tmpFiles = array();
		$this->attachReferenceImages($postFields, $options, $tmpFiles);

		return $this->doIdeogramRequest($apiKey, $endpoint, $postFields, $tmpFiles, $timeout, $userCallback);
	}

	public function edit($imageData, $maskData, $prompt, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'ideogram', 'key');
		$userCallback = Q::ifset($options, 'callback', null);
		$timeout = Q::ifset($options, 'timeout', 60);

		$postFields = array(
			'prompt' => $prompt,
			'rendering_speed' => Q::ifset($options, 'rendering_speed', 'DEFAULT'),
			'num_images' => Q::ifset($options, 'num_images', 1),
			'magic_prompt' => Q::ifset($options, 'magic_prompt', 'OFF'),
		);

		if (!empty($options['style_type'])) {
			$postFields['style_type'] = $options['style_type'];
		}

		$tmpFiles = array();

		// Attach main image
		$mainTmp = tempnam(sys_get_temp_dir(), 'ideo_edit_') . '.png';
		file_put_contents($mainTmp, Q_Utils::toRawBinary($imageData));
		$postFields['image'] = new CURLFile($mainTmp, 'image/png');
		$tmpFiles[] = $mainTmp;

		// Attach mask file
		$maskTmp = tempnam(sys_get_temp_dir(), 'ideo_mask_') . '.png';
		file_put_contents($maskTmp, Q_Utils::toRawBinary($maskData));
		$postFields['mask'] = new CURLFile($maskTmp, 'image/png');
		$tmpFiles[] = $maskTmp;

		$endpoint = '/v1/ideogram-v3/edit';
		return $this->doIdeogramRequest($apiKey, $endpoint, $postFields, $tmpFiles, $timeout, $userCallback);
	}

	// Common shared submission logic
	protected function doIdeogramRequest(
		$apiKey, $endpoint, &$postFields, &$tmpFiles, $timeout, $userCallback
	) {
		$result = array('data' => null, 'format' => 'png', 'error' => null);

		$callback = function ($info, $response) use (&$result, $userCallback, &$tmpFiles) {
			// Always delete temp files
			foreach ($tmpFiles as $tmp) {
				@unlink($tmp);
			}

			$httpCode = Q::ifset($info, 'http_code', 0);
			if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
				$json = json_decode($response, true);
				if (!empty($json['data'][0]['url'])) {
					$url = $json['data'][0]['url'];
					$binary = @file_get_contents($url);
					if ($binary === false) {
						$result['error'] = 'Download failed';
					} else {
						$result['data'] = $binary;
					}
				} else {
					$result['error'] = $json;
				}
			} else {
				$result['error'] = is_string($response)
					? (json_decode($response, true) ?: $response)
					: 'Invalid Ideogram response';
			}

			if ($userCallback && is_callable($userCallback)) {
				try {
					call_user_func($userCallback, $result);
				} catch (Exception $e) {
					error_log($e);
				}
			}
		};

		$response = Q_Utils::post(
			'https://api.ideogram.ai' . $endpoint,
			$postFields,
			null,
			null, // multipart
			array(
				"Api-Key: $apiKey",
				"Content-Type: multipart/form-data"
			),
			$timeout,
			$callback
		);

		return is_int($response) ? $result
			: ($result['error'] ? array('error' => $result['error']) : $result);
	}

	// Shared logic for adding character/reference image files and optional masks
	protected function attachReferenceImages(&$postFields, $options, &$tmpFiles)
	{
		if (!empty($options['character_reference_images'])
			&& is_array($options['character_reference_images'])) {

			foreach ($options['character_reference_images'] as $i => $binary) {
				$raw = Q_Utils::toRawBinary($binary);
				if ($raw === false) continue;

				$tmp = tempnam(sys_get_temp_dir(), 'ideo_ref_') . '.png';
				file_put_contents($tmp, $raw);
				$postFields["character_reference_images[$i]"] = new CURLFile($tmp, 'image/png');
				$tmpFiles[] = $tmp;

				// Attach mask if provided
				if (!empty($options['character_reference_images_mask'][$i])) {
					$maskRaw = Q_Utils::toRawBinary($options['character_reference_images_mask'][$i]);
					if ($maskRaw !== false) {
						$maskTmp = tempnam(sys_get_temp_dir(), 'ideo_ref_mask_') . '.png';
						file_put_contents($maskTmp, $maskRaw);
						$postFields["character_reference_images_mask[$i]"] = new CURLFile($maskTmp, 'image/png');
						$tmpFiles[] = $maskTmp;
					}
				}
			}
		}
	}
}