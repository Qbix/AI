<?php

class AI_Image_OpenAI extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generate an image via OpenAI Images API.
	 *
	 * @param string $prompt
	 * @param array  $options
	 * @return array ['data' => binary, 'format' => string] or ['error' => mixed]
	 */
	public function generate($prompt, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		$model   = Q::ifset($options, 'model', 'gpt-image-1.5');
		$format  = strtolower(Q::ifset($options, 'format', 'png'));
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

		$result = array(
			'data'   => null,
			'format' => $format,
			'error'  => null
		);

		/**
		 * Transport-level callback
		 * ALWAYS called as ($info, $responseBody)
		 */
		$callback = function ($info, $response) use (&$result, $format, $userCallback) {

			if (!is_string($response) || $response === '') {
				$result['error'] = 'Empty or invalid OpenAI response';
			} else {
				$data = json_decode($response, true);

				if (!is_array($data) || empty($data['data'][0]['b64_json'])) {
					$result['error'] = $data;
				} else {
					$pngBinary = base64_decode($data['data'][0]['b64_json'], true);
					if ($pngBinary === false) {
						$result['error'] = 'Failed to decode image data';
					} else {
						$converted = $this->convertFromPng($pngBinary, $format);
						if (!$converted) {
							$result['error'] = 'Image format conversion failed';
						} else {
							$result['data'] = $converted;
						}
					}
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

		// -------------------------------
		// TEXT-ONLY GENERATION
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
		// IMAGE-BASED GENERATION
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
                null, // IMPORTANT: let Q_Utils build multipart
                array(
                    "Authorization: Bearer $apiKey",
                    "Content-Type: multipart/form-data"
                ),
                $timeout,
                $callback
            );

            @unlink($tmp);
        }

		// Batch mode: callback fills result later
		if (is_int($response)) {
			return $result;
		}

		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result;
	}

	/**
	 * Convert OpenAI PNG output to requested format
	 */
	protected function convertFromPng($pngBinary, $format)
	{
		if ($format === 'png') {
			return $pngBinary;
		}

		$img = @imagecreatefromstring($pngBinary);
		if (!$img) {
			return false;
		}

		$w = imagesx($img);
		$h = imagesy($img);

		// Flatten alpha for JPG
		$canvas = imagecreatetruecolor($w, $h);
		$white = imagecolorallocate($canvas, 255, 255, 255);
		imagefill($canvas, 0, 0, $white);
		imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);

		ob_start();
		switch ($format) {
			case 'jpg':
			case 'jpeg':
				imagejpeg($canvas, null, 85);
				break;
			case 'webp':
				if (!function_exists('imagewebp')) {
					ob_end_clean();
					return false;
				}
				imagewebp($canvas, null, 85);
				break;
			default:
				ob_end_clean();
				return false;
		}
		$out = ob_get_clean();

		imagedestroy($img);
		imagedestroy($canvas);

		return $out;
	}

	/**
	 * Remove background using OpenAI image edits.
	 */
	public function removeBackground($image, $options = array())
	{
		$options['images'] = array($image);
		$options['prompt'] = Q::ifset($options, 'prompt', 'remove background');

		return $this->generate($options['prompt'], $options);
	}
}
