<?php

class AI_Image_OpenAI extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generate an image via OpenAI Images API.
	 *
	 * Supported options:
	 * - images: array of binary/base64/data-URI images (optional, ONLY FIRST USED)
	 * - model: string (default gpt-image-1)
	 * - format: png|jpg|webp
	 * - width: int
	 * - height: int
	 * - size: "1024x1024" (overrides width/height)
	 * - quality: standard|hd (mapped to auto|high)
	 * - timeout: int (seconds)
	 * - callback: callable (optional) function ($result)
	 *
	 * @param string $prompt
	 * @param array  $options
	 * @return array ['data' => binary, 'format' => string] or ['error' => mixed]
	 */
	public static function generate($prompt, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		$model   = Q::ifset($options, 'model', 'gpt-image-1');
		$format  = Q::ifset($options, 'format', 'png');
		$timeout = Q::ifset($options, 'timeout', 60);

		$userCallback = Q::ifset($options, 'callback', null);

		// --- Size normalization ---
		if (!empty($options['size']) && preg_match('/^(\d+)x(\d+)$/', $options['size'], $m)) {
			$size = $options['size'];
		} else {
			$w = Q::ifset($options, 'width', 1024);
			$h = Q::ifset($options, 'height', 1024);
			$size = "{$w}x{$h}";
		}

		$images   = Q::ifset($options, 'images', array());
		$useImage = is_array($images) && !empty($images);

		// Result container (shared for batch & non-batch)
		$result = array(
			'data'   => null,
			'format' => $format,
			'error'  => null
		);

		// Shared response handler
		$callback = function ($response) use (&$result, $userCallback) {

			if ($response === false || $response === null) {
				$result['error'] = 'OpenAI API unreachable';
			} else {
				$data = json_decode($response, true);
				if (!is_array($data) || empty($data['data'][0]['b64_json'])) {
					$result['error'] = $data;
				} else {
					$binary = base64_decode($data['data'][0]['b64_json']);
					if ($binary === false) {
						$result['error'] = 'Failed to decode image data';
					} else {
						$result['data'] = $binary;
					}
				}
			}

			// Invoke user callback if provided
			if ($userCallback && is_callable($userCallback)) {
				try {
					call_user_func($userCallback, $result);
				} catch (Exception $e) {
					error_log($e);
				}
			}
		};

		// -------------------------------
		// TEXT-ONLY GENERATION (JSON)
		// -------------------------------
		if (!$useImage) {

			$qualityMap = array(
				'standard' => 'auto',
				'hd'       => 'high'
			);
			$q = Q::ifset($options, 'quality', 'auto');
			$quality = Q::ifset($qualityMap, $q, $q);

			$payload = array(
				'model'   => $model,
				'prompt'  => $prompt,
				'size'    => $size,
				'quality' => $quality,
				'n'       => 1
			);

			$response = Q_Utils::post(
				'https://api.openai.com/v1/images/generations',
				json_encode($payload),
				null,
				false,
				array(
					"Authorization: Bearer $apiKey",
					"Content-Type: application/json"
				),
				$timeout,
				$callback
			);
		}

		// -------------------------------
		// IMAGE-BASED GENERATION (MULTIPART)
		// -------------------------------
		else {
			$raw = Q_Utils::toRawBinary(reset($images));
			if ($raw === false) {
				return array('error' => 'Invalid image input');
			}

			$tmp = tempnam(sys_get_temp_dir(), 'ai_img_');
			file_put_contents($tmp, $raw);

			$postFields = array(
				'model'  => $model,
				'prompt' => $prompt,
				'image'  => new CURLFile($tmp, 'image/png'),
				'size'   => $size,
				'n'      => 1
			);

			$response = Q_Utils::post(
				'https://api.openai.com/v1/images/edits',
				$postFields,
				null,
				true,
				array(
					"Authorization: Bearer $apiKey"
				),
				$timeout,
				$callback
			);

			@unlink($tmp);
		}

		// -------------------------------
		// Batch vs non-batch return
		// -------------------------------
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
	 * Remove background using OpenAI image edits.
	 *
	 * @method removeBackground
	 * @static
	 * @param {string} $image Raw binary, base64, or data URI
	 * @param {array}  $options
	 * @return {array}
	 */
	public static function removeBackground($image, $options = array())
	{
		$options['images'] = array($image);
		$options['prompt'] = Q::ifset($options, 'prompt', 'remove background');

		return self::generate($options['prompt'], $options);
	}
}
