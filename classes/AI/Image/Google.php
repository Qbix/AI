<?php

class AI_Image_Google extends AI_Image implements AI_Image_Interface
{
	const JPEG_QUALITY = 85;

	public function generate($prompt, $options = array())
	{
		$proxyUrl = rtrim(Q_Config::expect('AI', 'google', 'url'), '/');
		$clientId = Q_Config::expect('AI', 'google', 'clientId');
		$secret   = Q_Config::expect('AI', 'google', 'secret');

		$userCallback = Q::ifset($options, 'callback', null);
		$timestamp = time();

		$background = Q::ifset($options, 'background', 'none');
		$feather    = Q::ifset($options, 'feather', 0);

		// Feather only applies to transparency
		if ($background !== 'transparent') {
			$feather = 0;
		}

		$postFields = array(
			'prompt'     => $prompt,
			'format'     => Q::ifset($options, 'format', 'png'),
			'width'      => Q::ifset($options, 'width', 1024),
			'height'     => Q::ifset($options, 'height', 1024),
			'background' => $background,
			'feather'    => $feather
		);

		foreach ($postFields as $k => $v) {
			if ($v === null) unset($postFields[$k]);
		}

		// === Signature (clientId + timestamp only) ===
		$signature = hash_hmac(
			'sha256',
			$clientId . $timestamp,
			$secret
		);

		$headers = array(
			"X-Client-ID: $clientId",
			"X-Timestamp: $timestamp",
			"X-Signature: $signature",
			"Content-Type: multipart/form-data"
		);

		// === Attach images (optional, up to 5) ===
		$tmpFiles = array();
		$images = Q::ifset($options, 'images', array());

		if (is_array($images)) {
			foreach (array_slice($images, 0, 5) as $i => $binary) {

				$raw = Q_Utils::toRawBinary($binary);
				if ($raw === false) continue;

				$img = @imagecreatefromstring($raw);
				if (!$img) continue;

				$hasAlpha = $this->imageHasAlpha($img);

				if ($hasAlpha) {
					$tmp = tempnam(sys_get_temp_dir(), 'ai_img_') . '.png';
					imagepng($img, $tmp);
					$mime = 'image/png';
				} else {
					$tmp = tempnam(sys_get_temp_dir(), 'ai_img_') . '.jpg';
					imagejpeg($img, $tmp, self::JPEG_QUALITY);
					$mime = 'image/jpeg';
				}

				imagedestroy($img);

				$postFields['photo' . ($i + 1)] =
					new CURLFile($tmp, $mime);

				$tmpFiles[] = $tmp;
			}
		}

		$result = array(
			'data'   => null,
			'format' => Q::ifset($postFields, 'format', 'png'),
			'error'  => null
		);

		$callback = function ($info, $response) use (
			&$result,
			$userCallback,
			$tmpFiles
		) {
			foreach ($tmpFiles as $tmp) {
				@unlink($tmp);
			}

			$httpCode = Q::ifset($info, 'http_code', 0);

			if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
				$result['data'] = $response;
			} else {
				if (is_string($response)) {
					$json = json_decode($response, true);
					$result['error'] = $json ? $json : $response;
				} else {
					$result['error'] = 'Invalid proxy response';
				}
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
			$proxyUrl . '/generate',
			$postFields,
			null,
			null,
			$headers,
			Q::ifset($options, 'timeout', 60),
			$callback
		);

		if (is_int($response)) {
			return $result;
		}

		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result;
	}

	public function removeBackground($image, $options = array())
	{
		$options['images'] = array($image);
		$options['background'] = 'transparent';

		return $this->generate(
			Q::ifset($options, 'prompt', 'remove background'),
			$options
		);
	}

	protected function imageHasAlpha($img)
	{
		if (!imageistruecolor($img)) return false;
		return imagecolortransparent($img) >= 0;
	}
}
