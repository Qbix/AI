<?php

class AI_Image_RemoveBG extends AI_Image implements AI_Image_Interface
{
	const JPEG_QUALITY = 85;

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
	 *   @param {int}    [$options.timeout=60] Timeout in seconds
	 *   @param {callable} [$options.callback] function ($result)
	 * @return {array} Either ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public function removeBackground($base64Image, $options = array())
	{
		$apiKey       = Q_Config::expect('AI', 'removeBG', 'key');
		$url          = 'https://api.remove.bg/v1.0/removebg';
		$userCallback = Q::ifset($options, 'callback', null);

		$requestedFormat = strtolower(Q::ifset($options, 'format', 'auto'));

		$result = array(
			'data'   => null,
			'format' => $requestedFormat === 'auto' ? 'png' : $requestedFormat,
			'error'  => null
		);

		$raw = base64_decode($base64Image, true);
		if ($raw === false) {
			$result['error'] = 'Invalid base64 image input';
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
		if ($hasAlpha || $requestedFormat === 'png' || $requestedFormat === 'auto') {
			imagepng($img);
			$encoded  = ob_get_clean();
			$mime     = 'image/png';
			$filename = 'image.png';
			$sendFormat = 'png';
		} else {
			imagejpeg($img, null, self::JPEG_QUALITY);
			$encoded  = ob_get_clean();
			$mime     = 'image/jpeg';
			$filename = 'image.jpg';
			$sendFormat = 'jpg';
		}
		imagedestroy($img);

		$boundary = uniqid('rb');
		$eol = "\r\n";
		$body = '';

		$fields = array(
			'size'   => Q::ifset($options, 'size', 'auto'),
			'type'   => Q::ifset($options, 'type', 'auto'),
			'format' => $sendFormat
		);

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
		       . "Content-Disposition: form-data; name=\"image_file\"; filename=\"$filename\"$eol"
		       . "Content-Type: $mime$eol$eol"
		       . $encoded . $eol;

		$body .= "--$boundary--$eol";

		$headers = array(
			"X-Api-Key: $apiKey",
			"Content-Type: multipart/form-data; boundary=$boundary"
		);

		$response = Q_Utils::post(
			$url,
			$body,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		if ($response === false) {
			$result['error'] = 'HTTP or timeout error';
		} else {
			if (!@imagecreatefromstring($response)) {
				$json = json_decode($response, true);
				if (json_last_error() === JSON_ERROR_NONE && !empty($json['errors'])) {
					$messages = array();
					foreach ($json['errors'] as $error) {
						$title  = isset($error['title'])  ? $error['title']  : 'Unknown';
						$detail = isset($error['detail']) ? $error['detail'] : '';
						$messages[] = "$title: $detail";
					}
					$result['error'] = implode(",\n", $messages);
				} else {
					$result['error'] = 'Unknown error';
				}
			} else {
				$result['data']   = $response;
				$result['format'] = $sendFormat;
			}
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