<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_Image_AWS extends AI_Image implements AI_Image_Interface
{
	/**
	 * Cached Bedrock client
	 * @return BedrockRuntimeClient
	 */
	protected static function getClient()
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

	/**
	 * Generates an image from a text prompt using AWS Bedrock.
	 *
	 * @method generate
	 * @static
	 * @param {string} $prompt The prompt to generate an image from
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.model="stability.stable-diffusion-xl-v0"]
	 *   @param {string} [$options.size="1024x1024"]
	 *   @param {int} [$options.steps=50]
	 * @return {array} ['b64_json'=>string] or ['error'=>string]
	 */
	public static function generate($prompt, $options = [])
	{
		$client  = self::getClient();
		$modelId = Q::ifset($options, 'model', 'stability.stable-diffusion-xl-v0');
		$size    = Q::ifset($options, 'size', '1024x1024');
		list($width, $height) = explode('x', $size);

		$payload = [
			'text_prompts' => [['text' => $prompt]],
			'cfg_scale'    => 10,
			'steps'        => Q::ifset($options, 'steps', 50),
			'seed'         => rand(0, 1000000),
			'width'        => (int) $width,
			'height'       => (int) $height,
		];

		try {
			$result = $client->invokeModel([
				'modelId'     => $modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			]);

			$response = json_decode($result['body']->getContents(), true);
			if (!empty($response['artifacts'][0]['base64'])) {
				return ['b64_json' => $response['artifacts'][0]['base64']];
			}
			return ['error' => json_encode($response)];
		} catch (Exception $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Removes the background from an image using AWS Bedrock.
	 * Uses Stability AI’s inpainting model (sd-remix) or Titan image generator.
	 *
	 * @method removeBackground
	 * @static
	 * @param {string} $base64Image Base64-encoded PNG/JPG (no data URI prefix)
	 * @param {array} $options Optional parameters:
	 *   @param {string} [$options.model="stability.sd-remix"]
	 *   @param {string} [$options.prompt="remove background"]
	 *   @param {string} [$options.mask_source="background"]
	 *   @param {string} [$options.format="png"]
	 *   @param {int} [$options.steps=40]
	 * @return {array} ['data'=>binary,'format'=>string] or ['error'=>string]
	 */
	public static function removeBackground($base64Image, $options = [])
	{
		$client  = self::getClient();
		$modelId = Q::ifset($options, 'model', 'stability.sd-remix');
		$prompt  = Q::ifset($options, 'prompt', 'remove background');
		$format  = Q::ifset($options, 'format', 'png');

		$payload = [
			'image'         => $base64Image,
			'mask_source'   => Q::ifset($options, 'mask_source', 'background'),
			'text_prompts'  => [['text' => $prompt]],
			'cfg_scale'     => 10,
			'steps'         => Q::ifset($options, 'steps', 40),
			'seed'          => rand(0, 1000000),
			'output_format' => $format
		];

		try {
			$result = $client->invokeModel([
				'modelId'     => $modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			]);

			$response = json_decode($result['body']->getContents(), true);
			if (!empty($response['artifacts'][0]['base64'])) {
				$data = base64_decode($response['artifacts'][0]['base64']);
				if ($data === false) {
					return ['error' => 'Invalid base64 output'];
				}
				return ['data' => $data, 'format' => $format];
			}
			return ['error' => json_encode($response)];
		} catch (Exception $e) {
			return ['error' => $e->getMessage()];
		}
	}
}