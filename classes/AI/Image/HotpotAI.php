<?php

class AI_Image_HotpotAI extends AI_Image implements AI_Image_Interface
{
	/**
	 * Removes the background from an image using Hotpot AI.
	 * 
	 * @method removeBackground
	 * @static
	 * @param {string} $image Binary or Base64-encoded image string (no data URI prefix)
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.backgroundImage] A background image as base64 or URL
	 *   @param {string} [$options.backgroundColor] A solid background color (e.g. "#ffffff")
	 *   @param {bool} [$options.returnAlpha=true] Whether to return transparency
	 *   @param {string} [$options.fileType="png"] File type of result (png, jpg, webp)
	 *   @param {int} [$options.compressionFactor=100] Compression level (10–100)
	 *   @param {int} [$options.timeout=60] Request timeout in seconds
	 * @return {array} Either ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public static function removeBackground($image, $options = [])
	{
		$apiKey = Q_Config::expect('AI', 'hotpot', 'key');
		$url = 'https://api.hotpot.ai/remove-background';

		$image = Q_Utils::toRawBinary($image);
		if ($image === false) {
			return ['error' => 'Invalid base64 image'];
		}

		$boundary = uniqid('hp');
		$eol = "\r\n";
		$body = '';

		foreach (['backgroundColor','fileType','compressionFactor','returnAlpha'] as $f) {
			if (isset($options[$f])) {
				$val = is_bool($options[$f]) ? ($options[$f] ? 'true' : 'false') : $options[$f];
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"$f\"$eol$eol"
				       . $val . $eol;
			}
		}

		$body .= "--$boundary$eol"
		       . "Content-Disposition: form-data; name=\"image\"; filename=\"input.png\"$eol"
		       . "Content-Type: application/octet-stream$eol$eol"
		       . $image . $eol;

		if (!empty($options['backgroundImage'])) {
			$bg = base64_decode($options['backgroundImage']);
			if ($bg !== false) {
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"backgroundImage\"; filename=\"bg.png\"$eol"
				       . "Content-Type: application/octet-stream$eol$eol"
				       . $bg . $eol;
			} else {
				$body .= "--$boundary$eol"
				       . "Content-Disposition: form-data; name=\"backgroundUrl\"$eol$eol"
				       . $options['backgroundImage'] . $eol;
			}
		}

		$body .= "--$boundary--$eol";

		$headers = [
			"Authorization: $apiKey",
			"Content-Type: multipart/form-data; boundary=$boundary"
		];

		$resp = Q_Utils::post($url, $body, null, true, $headers, Q::ifset($options, 'timeout', 60));
		if ($resp === false) {
			return ['error' => 'HTTP or timeout error'];
		}

		return ['data' => $resp, 'format' => Q::ifset($options, 'fileType', 'png')];
	}

	/**
	 * Generates an image from a text prompt using Hotpot AI.
	 *
	 * @method generate
	 * @static
	 * @param {string} $prompt The prompt describing the image
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.styleId="default"] The style ID for the artwork
	 *   @param {string} [$options.seedImage] A seed image URL to guide generation
	 *   @param {string} [$options.negativePrompt] Text to avoid
	 *   @param {float} [$options.promptStrength] Strength of prompt guidance
	 *   @param {bool} [$options.isRandom] Whether to use random seed
	 *   @param {bool} [$options.isTile] Whether to tile the image
	 *   @param {int} [$options.timeout=60] Request timeout in seconds
	 * @return {array} Either ['data' => binary, 'format' => 'png'] or ['error' => string]
	 */
	public static function generate($prompt, $options = [])
	{
		$apiKey = Q_Config::expect('AI', 'hotpot', 'key');
		$url = 'https://api.hotpot.ai/make-art';

		$form = [
			'inputText' => $prompt,
			'styleId'   => Q::ifset($options, 'styleId', 'default'),
		];

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

		$headers = [
			"Authorization: $apiKey"
		];

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

		$resp = Q_Utils::post($url, $body, null, true, $headers, Q::ifset($options, 'timeout', 60));
		if ($resp === false) {
			return ['error' => 'HTTP or timeout error'];
		}

		return ['data' => $resp, 'format' => 'png'];
	}
}
