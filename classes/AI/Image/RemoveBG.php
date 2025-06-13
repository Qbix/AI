<?php

class AI_Image_RemoveBG extends AI_Image implements AI_Image_Interface
{
	/**
	 * Removes the background from an image using Remove.bg API.
	 *
	 * @method removeBackground
	 * @static
	 * @param {string} $base64Image Base64-encoded image string (without the data URI prefix)
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.size="auto"] Result image size: "auto", "preview", "full"
	 *   @param {string} [$options.type="auto"] Type of image: "auto", "person", "product", "car"
	 *   @param {string} [$options.format="auto"] Output format: "auto", "png", "jpg", "zip"
	 *   @param {string} [$options.bg_color] Background color (e.g. "#ffffff")
	 *   @param {string} [$options.bg_image_url] URL of a custom background image
	 *   @param {int} [$options.timeout=60] Timeout in seconds
	 * @return {array} Either ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public static function removeBackground($base64Image, $options = [])
	{
		$apiKey = Q_Config::expect('AI', 'removeBG', 'key');
		$url = 'https://api.remove.bg/v1.0/removebg';

		$image = base64_decode($base64Image);
		if ($image === false) {
			return ['error' => 'Invalid base64 image input'];
		}

		$boundary = uniqid('rb');
		$eol = "\r\n";
		$body = '';

		$fields = [
			'size'   => Q::ifset($options, 'size', 'auto'),
			'type'   => Q::ifset($options, 'type', 'auto'),
			'format' => Q::ifset($options, 'format', 'auto'),
		];

		if (!empty($options['bg_color'])) {
			$fields['bg_color'] = $options['bg_color'];
		}
		if (!empty($options['bg_image_url'])) {
			$fields['bg_image_url'] = $options['bg_image_url'];
		}

		foreach ($fields as $key => $value) {
			$body .= "--$boundary$eol"
			       . "Content-Disposition: form-data; name=\"$key\"$eol$eol"
			       . $value . $eol;
		}

		$body .= "--$boundary$eol"
		       . "Content-Disposition: form-data; name=\"image_file\"; filename=\"image.png\"$eol"
		       . "Content-Type: application/octet-stream$eol$eol"
		       . $image . $eol;

		$body .= "--$boundary--$eol";

		$headers = [
			"X-Api-Key: $apiKey",
			"Content-Type: multipart/form-data; boundary=$boundary"
		];

		$response = Q_Utils::post(
			$url,
			$body,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		if ($response === false) {
			return ['error' => 'HTTP or timeout error'];
		}

		return ['data' => $response, 'format' => Q::ifset($options, 'format', 'png')];
	}
}
