<?php

class AI_Image_Hotpotai extends AI_Image implements AI_Image_Interface
{
	const JPEG_QUALITY = 85;

	/**
	 * Removes the background from an image using Hotpot AI.
	 * 
	 * @method removeBackground
	 * @static
	 * @param {string} $image Binary or Base64-encoded image string (no data URI prefix)
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.backgroundImage] A background image as base64 or URL
	 *   @param {string} [$options.backgroundColor] A solid background color (e.g. "#ffffff")
	 *   @param {bool}   [$options.returnAlpha=true] Whether to return transparency
	 *   @param {string} [$options.fileType="png"] File type of result (png or jpg)
	 *   @param {int}    [$options.compressionFactor=100] Compression level (10–100)
	 *   @param {int}    [$options.timeout=60] Request timeout in seconds
	 *   @param {callable} [$options.callback] function ($result)
	 * @return {array} Either ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public function removeBackground($image, $options = array())
	{
		$apiKey       = Q_Config::expect('AI', 'hotpot', 'key');
		$url          = 'https://api.hotpot.ai/remove-background';
		$userCallback = Q::ifset($options, 'callback', null);

		$format = strtolower(Q::ifset($options, 'fileType', 'png'));

		$result = array(
			'data'   => null,
			'format' => $format,
			'error'  => null
		);

		$raw = Q_Utils::toRawBinary($image);
		if ($raw === false) {
			$result['error'] = 'Invalid image input';
			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $result);
			}
			return array('error' => $result['error']);
		}

		$img = @imagecreatefromstring($raw);
		if (!$img) {
			$result['error'] = 'Unable to decode image';
			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $result);
			}
			return array('error' => $result['error']);
		}

		$hasAlpha = $this->imageHasAlpha($img);

		ob_start();
		if ($hasAlpha || $format === 'png') {
			imagepng($img);
			$encoded = ob_get_clean();
			$mime    = 'image/png';
			$filename = 'input.png';
			$format  = 'png';
		} else {
			imagejpeg($img, null, self::JPEG_QUALITY);
			$encoded = ob_get_clean();
			$mime    = 'image/jpeg';
			$filename = 'input.jpg';
			$format  = 'jpg';
		}
		imagedestroy($img);

		$boundary = uniqid('hp');
		$eol = "\r\n";
		$body = '';

		foreach (['backgroundColor','compressionFactor','returnAlpha'] as $f) {
			if (isset($options[$f])) {
				$val = is_bool($options[$f]) ? ($options[$f] ? 'true' : 'false') : $options[$f];
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"$f\"$eol$eol"
				       . $val . $eol;
			}
		}

		$body .= "--$boundary$eol"
		       . "Content-Disposition: form-data; name=\"image\"; filename=\"$filename\"$eol"
		       . "Content-Type: $mime$eol$eol"
		       . $encoded . $eol;

		if (!empty($options['backgroundImage'])) {
			$bgRaw = base64_decode($options['backgroundImage'], true);
			if ($bgRaw !== false) {
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"backgroundImage\"; filename=\"bg.png\"$eol"
				       . "Content-Type: image/png$eol$eol"
				       . $bgRaw . $eol;
			} else {
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"backgroundUrl\"$eol$eol"
				       . $options['backgroundImage'] . $eol;
			}
		}

		$body .= "--$boundary--$eol";

		$headers = array(
			"Authorization: $apiKey",
			"Content-Type: multipart/form-data; boundary=$boundary"
		);

		$resp = Q_Utils::post(
			$url,
			$body,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		if ($resp === false) {
			$result['error'] = 'HTTP or timeout error';
		} else {
			$result['data']   = $resp;
			$result['format'] = $format;
		}

		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return array(
			'data'   => $result['data'],
			'format' => $result['format']
		);
	}

	/**
	 * Generates an image from a text prompt using Hotpot AI.
	 *
	 * @method generate
	 * @static
	 * @param {string} $prompt The prompt describing the image
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.styleId="default"]
	 *   @param {string} [$options.seedImage]
	 *   @param {string} [$options.negativePrompt]
	 *   @param {float}  [$options.promptStrength]
	 *   @param {bool}   [$options.isRandom]
	 *   @param {bool}   [$options.isTile]
	 *   @param {int}    [$options.timeout=60]
	 *   @param {callable} [$options.callback] function ($result)
	 * @return {array} Either ['data'=>binary,'format'=>'png'] or ['error'=>string]
	 */
	public static function generate($prompt, $options = [])
	{
		$apiKey       = Q_Config::expect('AI', 'hotpot', 'key');
		$url          = 'https://api.hotpot.ai/make-art';
		$userCallback = Q::ifset($options, 'callback', null);

		$result = array(
			'data'   => null,
			'format' => 'png',
			'error'  => null
		);

		$form = array(
			'inputText' => $prompt,
			'styleId'   => Q::ifset($options, 'styleId', 'default'),
		);

		foreach (['seedImage','negativePrompt'] as $f) {
			if (!empty($options[$f])) {
				$form[$f] = $options[$f];
			}
		}
		foreach (['promptStrength','isRandom','isTile'] as $f) {
			if (isset($options[$f])) {
				$form[$f] = $options[$f];
			}
		}

		$headers = array(
			"Authorization: $apiKey"
		);

		$boundary = uniqid('hotpot');
		$eol = "\r\n";
		$body = '';

		foreach ($form as $name => $val) {
			$body .= "--$boundary$eol"
			       . "Content-Disposition: form-data; name=\"$name\"$eol$eol"
			       . $val . $eol;
		}
		$body .= "--$boundary--$eol";

		$headers[] = "Content-Type: multipart/form-data; boundary=$boundary";

		$resp = Q_Utils::post(
			$url,
			$body,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		if ($resp === false) {
			$result['error'] = 'HTTP or timeout error';
		} else {
			$result['data'] = $resp;
		}

		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return array(
			'data'   => $result['data'],
			'format' => 'png'
		);
	}

	/**
	 * Detect whether a GD image resource has an alpha channel.
	 *
	 * @method imageHasAlpha
	 * @protected
	 * @param {resource} $img
	 * @return {boolean}
	 */
	protected function imageHasAlpha($img)
	{
		if (!imageistruecolor($img)) return false;
		return imagecolortransparent($img) >= 0;
	}
}
