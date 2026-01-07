<?php

class AI_Image_Google extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generate an image via Google Vertex (Nano Banana) through the Node proxy.
	 *
	 * Supported options:
	 * - photos: array of binary image strings (optional, up to 5)
	 * - format: png|jpg|webp|gif
	 * - width: int
	 * - height: int
	 * - background: none|transparent|gradient
	 * - feather: int (0–100)
	 * - timeout: int (seconds)
	 *
	 * @param string $prompt
	 * @param array  $options
	 * @return array ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public static function generate($prompt, $options = array())
	{
		$proxyUrl = rtrim(Q_Config::expect('AI', 'google', 'url'), '/');
		$clientId = Q_Config::expect('AI', 'google', 'clientId');
		$secret   = Q_Config::expect('AI', 'google', 'secret');

		$timestamp = time();

		// --- Signature (must match Node exactly: clientId + timestamp) ---
		$signature = hash_hmac(
			'sha256',
			$clientId . $timestamp,
			$secret
		);

		$headers = array(
			"X-Client-ID: $clientId",
			"X-Timestamp: $timestamp",
			"X-Signature: $signature"
		);

		// --- Base multipart fields ---
		$postFields = array(
			'prompt'     => $prompt,
			'format'     => Q::ifset($options, 'format', 'png'),
			'width'      => Q::ifset($options, 'width'),
			'height'     => Q::ifset($options, 'height'),
			'background' => Q::ifset($options, 'background', 'none'),
			'feather'    => Q::ifset($options, 'feather')
		);

		// Remove null values
		foreach ($postFields as $k => $v) {
			if ($v === null) {
				unset($postFields[$k]);
			}
		}

		// --- Attach photos (multipart, binary, optional) ---
		$tmpFiles = array();
		$photos = Q::ifset($options, 'photos', array());

		if (is_array($photos)) {
			$photos = array_slice($photos, 0, 5);

			foreach ($photos as $i => $binary) {
				if (!is_string($binary)) continue;

				$tmp = tempnam(sys_get_temp_dir(), 'ai_img_');
				file_put_contents($tmp, Q_Utils::toRawBinary($binary));

				$field = 'photo' . ($i + 1);
				$postFields[$field] = new CURLFile($tmp, 'image/png');

				$tmpFiles[] = $tmp;
			}
		}

		// --- Execute request ---
		$response = Q_Utils::post(
			$proxyUrl . '/generate',
			$postFields,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		// Cleanup temp files
		foreach ($tmpFiles as $tmp) {
			@unlink($tmp);
		}

		if ($response === false) {
			return array('error' => 'Proxy unreachable');
		}

		// --- Binary image response ---
		$trimmed = ltrim($response);
		if (substr($trimmed, 0, 1) !== '{') {
			return array(
				'data'   => $response,
				'format' => Q::ifset($options, 'format', 'png')
			);
		}

		// --- JSON error response ---
		$json = json_decode($response, true);
		return $json ? $json : array('error' => 'Invalid proxy response');
	}

	/**
	 * Removes the background from an image using Google Vertex (via proxy).
	 *
	 * @method removeBackground
	 * @static
	 * @param {string} $image Raw binary, base64, or data URI
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.format="png"]
	 *   @param {int}    [$options.timeout=60]
	 * @return {array} ['data'=>binary,'format'=>string] or ['error'=>string]
	 */
	public static function removeBackground($image, $options = array())
	{
		$options['photos'] = array($image);
		$options['background'] = 'transparent';

		return self::generate(
			Q::ifset($options, 'prompt', 'remove background'),
			$options
		);
	}

}