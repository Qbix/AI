<?php

class AI_Image_OpenAI extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generate an image via OpenAI Images API.
	 *
	 * Supported options (aligned with Google adapter):
	 * - photos: array of binary image strings (optional, first used as base image)
	 * - format: png|jpg|webp
	 * - width: int
	 * - height: int
	 * - size: "1024x1024" (overrides width/height)
	 * - quality: standard|hd
	 * - timeout: int (seconds)
	 *
	 * @param string $prompt
	 * @param array  $options
	 * @return array ['data' => binary, 'format' => string] or ['error' => string]
	 */
	public static function generate($prompt, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');
		$endpoint = 'https://api.openai.com/v1/images/generations';

		// --- Normalize size handling ---
		if (!empty($options['size']) && preg_match('/^(\d+)x(\d+)$/', $options['size'], $m)) {
			$width  = (int)$m[1];
			$height = (int)$m[2];
		} else {
			$width  = Q::ifset($options, 'width', 1024);
			$height = Q::ifset($options, 'height', 1024);
		}
		$size = "{$width}x{$height}";

		// --- Base fields ---
		$postFields = array(
			'prompt' => $prompt,
			'model'  => Q::ifset($options, 'model', 'gpt-image-1'),
			'size'   => $size,
			'quality'=> Q::ifset($options, 'quality', 'standard'),
			'n'      => 1
		);

		$headers = array(
			"Authorization: Bearer $apiKey"
		);

		// --- Reference image support (OpenAI: single base image only) ---
		$tmpFiles = array();
		$photos = Q::ifset($options, 'photos', array());

		if (is_array($photos) && !empty($photos)) {
			$binary = reset($photos);
			if (is_string($binary)) {
				$tmp = tempnam(sys_get_temp_dir(), 'ai_img_');
				file_put_contents($tmp, Q_Utils::toRawBinary($binary));
				$postFields['image'] = new CURLFile($tmp, 'image/png');
				$tmpFiles[] = $tmp;

				// Switch endpoint to edits when base image is provided
				$endpoint = 'https://api.openai.com/v1/images/edits';
			}
		}

		// --- Execute request ---
		$response = Q_Utils::post(
			$endpoint,
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
			return array('error' => 'OpenAI API unreachable');
		}

		$data = json_decode($response, true);
		if (empty($data['data'][0]['b64_json'])) {
			return array('error' => $data);
		}

		$binary = base64_decode($data['data'][0]['b64_json']);
		if (!$binary) {
			return array('error' => 'Failed to decode image data');
		}

		$format = Q::ifset($options, 'format', 'png');

		return array(
			'data'   => $binary,
			'format' => $format
		);
	}

    /**
     * Removes the background from an image using OpenAI Images API.
     *
     * @method removeBackground
     * @static
     * @param {string} $image Raw binary, base64, or data URI
     * @param {array} $options Optional parameters:
     *   @param {string} [$options.prompt="remove background"]
     *   @param {string} [$options.format="png"]
     *   @param {int}    [$options.timeout=60]
     * @return {array} ['data'=>binary,'format'=>string] or ['error'=>string]
     */
    public static function removeBackground($image, $options = array())
    {
        $apiKey   = Q_Config::expect('AI', 'openAI', 'key');
        $endpoint = 'https://api.openai.com/v1/images/edits';
        $prompt   = Q::ifset($options, 'prompt', 'remove background');
        $format   = Q::ifset($options, 'format', 'png');

        $raw = Q_Utils::toRawBinary($image);
        if ($raw === false) {
            return array('error' => 'Invalid image input');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ai_img_');
        file_put_contents($tmp, $raw);

        $postFields = array(
            'model'  => Q::ifset($options, 'model', 'gpt-image-1'),
            'prompt' => $prompt,
            'image'  => new CURLFile($tmp, 'image/png'),
            'n'      => 1
        );

        $headers = array(
            "Authorization: Bearer $apiKey"
        );

        $response = Q_Utils::post(
            $endpoint,
            $postFields,
            null,
            true,
            $headers,
            Q::ifset($options, 'timeout', 60)
        );

        @unlink($tmp);

        if ($response === false) {
            return array('error' => 'OpenAI API unreachable');
        }

        $data = json_decode($response, true);
        if (empty($data['data'][0]['b64_json'])) {
            return array('error' => $data);
        }

        $binary = base64_decode($data['data'][0]['b64_json']);
        if ($binary === false) {
            return array('error' => 'Failed to decode image data');
        }

        return array(
            'data'   => $binary,
            'format' => $format
        );
    }

}
