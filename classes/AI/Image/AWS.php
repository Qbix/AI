<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_Image_AWS extends AI_Image implements AI_Image_Interface
{
	const JPEG_QUALITY = 85;

	/**
	 * Generates an image from a text prompt using AWS Bedrock.
	 */
	public function generate($prompt, $options = array())
	{
		$client       = $this->getClient();
		$modelId      = Q::ifset($options, 'model', 'stability.stable-diffusion-xl-v0');
		$size         = Q::ifset($options, 'size', '1024x1024');
		$steps        = Q::ifset($options, 'steps', 50);
		$userCallback = Q::ifset($options, 'callback', null);

		list($width, $height) = explode('x', $size);

		$result = [
			'b64_json' => null,
			'error'    => null
		];

		$payload = [
			'text_prompts' => [['text' => $prompt]],
			'cfg_scale'    => 10,
			'steps'        => $steps,
			'seed'         => rand(0, 1000000),
			'width'        => (int) $width,
			'height'       => (int) $height,
		];

		try {
			$invokeResult = $client->invokeModel([
				'modelId'     => $modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			]);

			$response = json_decode($invokeResult['body']->getContents(), true);

			if (!empty($response['artifacts'][0]['base64'])) {
				$result['b64_json'] = $response['artifacts'][0]['base64'];
			} else {
				$result['error'] = json_encode($response);
			}
		} catch (Exception $e) {
			$result['error'] = $e->getMessage();
		}

		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		if ($result['error']) {
			return ['error' => $result['error']];
		}

		return ['b64_json' => $result['b64_json']];
	}

	/**
	 * Removes the background from an image using AWS Bedrock.
	 */
	public function removeBackground($image, $options = array())
	{
		$client       = $this->getClient();
		$modelId      = Q::ifset($options, 'model', 'stability.sd-remix');
		$prompt       = Q::ifset($options, 'prompt', 'remove background');
		$format       = Q::ifset($options, 'format', 'png');
		$steps        = Q::ifset($options, 'steps', 40);
		$userCallback = Q::ifset($options, 'callback', null);

		$result = [
			'data'   => null,
			'format' => $format,
			'error'  => null
		];

		// --- Normalize input image (JPEG vs PNG, no WebP) ---
		$raw = Q_Utils::toRawBinary($image);
		if ($raw === false) {
			return ['error' => 'Invalid image input'];
		}

		$img = @imagecreatefromstring($raw);
		if (!$img) {
			return ['error' => 'Unable to decode image'];
		}

		$hasAlpha = $this->imageHasAlpha($img);

		ob_start();
		if ($hasAlpha || $format === 'png') {
			imagepng($img);
			$encoded = ob_get_clean();
			$format  = 'png';
		} else {
			imagejpeg($img, null, self::JPEG_QUALITY);
			$encoded = ob_get_clean();
			$format  = 'jpg';
		}
		imagedestroy($img);

		$payload = [
			'image'         => base64_encode($encoded),
			'mask_source'   => Q::ifset($options, 'mask_source', 'background'),
			'text_prompts'  => [['text' => $prompt]],
			'cfg_scale'     => 10,
			'steps'         => $steps,
			'seed'          => rand(0, 1000000),
			'output_format' => $format
		];

		try {
			$invokeResult = $client->invokeModel([
				'modelId'     => $modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			]);

			$response = json_decode($invokeResult['body']->getContents(), true);

			if (!empty($response['artifacts'][0]['base64'])) {
				$data = base64_decode($response['artifacts'][0]['base64']);
				if ($data === false) {
					$result['error'] = 'Invalid base64 output';
				} else {
					$result['data'] = $data;
					$result['format'] = $format;
				}
			} else {
				$result['error'] = json_encode($response);
			}
		} catch (Exception $e) {
			$result['error'] = $e->getMessage();
		}

		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		if ($result['error']) {
			return ['error' => $result['error']];
		}

		return [
			'data'   => $result['data'],
			'format' => $result['format']
		];
	}

	/**
	 * Cached Bedrock client
	 */
	protected function getClient()
	{
		static $client = null;
		if (!$client) {
			$key    = Q_Config::expect('AI', 'aws', 'key');
			$secret = Q_Config::expect('AI', 'aws', 'secret');
			$region = Q_Config::get('AI', 'aws', 'region', 'us-east-1');

			$client = new BedrockRuntimeClient([
				'region'      => $region,
				'version'     => 'latest',
				'credentials' => [
					'key'    => $key,
					'secret' => $secret,
				],
			]);
		}
		return $client;
	}

	protected function imageHasAlpha($img)
	{
		if (!imageistruecolor($img)) return false;
		return imagecolortransparent($img) >= 0;
	}
}