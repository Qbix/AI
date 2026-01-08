<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_Image_AWS extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generates an image from a text prompt using AWS Bedrock.
	 *
	 * @method generate
	 * @static
	 * @param {string} $prompt The prompt to generate an image from
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.model="stability.stable-diffusion-xl-v0"]
	 *   @param {string} [$options.size="1024x1024"]
	 *   @param {int}    [$options.steps=50]
	 *   @param {callable} [$options.callback] function ($result)
	 * @return {array} ['b64_json'=>string] or ['error'=>string]
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

		// Invoke user callback if provided
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
	 * Uses Stability AI’s inpainting model (sd-remix) or Titan image generator.
	 *
	 * @method removeBackground
	 * @static
	 * @param {string} $image Binary or Base64-encoded PNG/JPG (no data URI prefix)
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.model="stability.sd-remix"]
	 *   @param {string} [$options.prompt="remove background"]
	 *   @param {string} [$options.mask_source="background"]
	 *   @param {string} [$options.format="png"]
	 *   @param {int}    [$options.steps=40]
	 *   @param {callable} [$options.callback] function ($result)
	 * @return {array} ['data'=>binary,'format'=>string] or ['error'=>string]
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

		$payload = [
			'image'         => Q_Utils::toBase64($image),
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
				}
			} else {
				$result['error'] = json_encode($response);
			}
		} catch (Exception $e) {
			$result['error'] = $e->getMessage();
		}

		// Invoke user callback if provided
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

		return array(
			'data'   => $result['data'],
			'format' => $format
		);
	}

	/**
	 * Cached Bedrock client
	 * @return BedrockRuntimeClient
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
}
