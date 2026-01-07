<?php

class AI_Image_Google extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generate an image via Google Vertex (Nano Banana) through the Node proxy.
	 *
	 * Supported options:
	 * - images: array of binary image strings (optional, up to 5)
	 * - format: png|jpg|webp|gif
	 * - width: int
	 * - height: int
	 * - background: none|transparent|gradient
	 * - feather: int (0–100)
	 * - timeout: int (seconds)
	 * - callback: callable (optional) function ($result)
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

		$userCallback = Q::ifset($options, 'callback', null);

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

		// --- Attach images (multipart, binary, optional) ---
		$tmpFiles = array();
		$images = Q::ifset($options, 'images', array());

		if (is_array($images)) {
			$images = array_slice($images, 0, 5);

			foreach ($images as $i => $binary) {
				if (!is_string($binary)) continue;

				$tmp = tempnam(sys_get_temp_dir(), 'ai_img_');
				file_put_contents($tmp, Q_Utils::toRawBinary($binary));

				$field = 'photo' . ($i + 1);
				$postFields[$field] = new CURLFile($tmp, 'image/png');

				$tmpFiles[] = $tmp;
			}
		}

		// --- Result container (shared for batch & non-batch) ---
		$result = array(
			'data'   => null,
			'format' => Q::ifset($options, 'format', 'png'),
			'error'  => null
		);

		// --- Response handler ---
		$callback = function ($response) use (&$result, $userCallback) {
			if ($response === false || $response === null) {
				$result['error'] = 'Proxy unreachable';
			} else {
				// Binary image response
				$trimmed = ltrim($response);
				if ($trimmed !== '' && substr($trimmed, 0, 1) !== '{') {
					$result['data'] = $response;
				} else {
					// JSON error response
					$json = json_decode($response, true);
					$result['error'] = $json ? $json : 'Invalid proxy response';
				}
			}

			// --- Invoke user callback, if provided ---
			if ($userCallback && is_callable($userCallback)) {
				try {
					call_user_func($userCallback, $result);
				} catch (Exception $e) {
					error_log($e);
				}
			}
		};

		// --- Execute request ---
		$response = Q_Utils::post(
			$proxyUrl . '/generate',
			$postFields,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60),
			$callback
		);

		// Cleanup temp files (safe for both batch & non-batch)
		foreach ($tmpFiles as $tmp) {
			@unlink($tmp);
		}

		// --- Batch vs non-batch handling ---
		if (is_int($response)) {
			// Batch mode: callback will populate $result later
			return $result;
		}

		// Non-batch: callback already executed
		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result;
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
		$options['images'] = array($image);
		$options['background'] = 'transparent';

		return self::generate(
			Q::ifset($options, 'prompt', 'remove background'),
			$options
		);
	}
}
