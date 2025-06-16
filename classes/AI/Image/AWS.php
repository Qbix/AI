<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_Image_AWS extends AI_Image implements AI_Image_Interface
{
	protected static function getClient()
	{
		static $client = null;
		if (!$client) {
			$client = new BedrockRuntimeClient([
				'region' => Q_Config::expect('AI', 'aws', 'region'),
				'version' => 'latest',
			]);
		}
		return $client;
	}

	/**
	 * @method generate
	 * @param {string} $prompt The prompt to generate an image from
	 * @param {array} $options Optional settings:
	 * @param {string} [$options.model="stability.stable-diffusion-xl-v0"] The model to use
	 * @param {string} [$options.response_format="b64_json"] Only base64 is supported here
	 * @param {string} [$options.size="1024x1024"] Image size (can hint at height/width)
	 * @return {array} Either ['b64_json' => ...] or ['error' => ...]
	 */
	public static function generate($prompt, $options = array())
	{
		$client = self::getClient();

		$modelId = Q::ifset($options, 'model', 'stability.stable-diffusion-xl-v0');
		$size = Q::ifset($options, 'size', '1024x1024');
		list($width, $height) = explode('x', $size);

		$payload = [
			'text_prompts' => [[ 'text' => $prompt ]],
			'cfg_scale' => 10,
			'steps' => 50,
			'seed' => rand(0, 1000000),
			'width' => (int) $width,
			'height' => (int) $height,
		];

		try {
			$result = $client->invokeModel([
				'modelId' => $modelId,
				'body' => json_encode($payload),
				'contentType' => 'application/json',
				'accept' => 'application/json',
			]);

			$response = json_decode($result['body']->getContents(), true);
			if (isset($response['artifacts'][0]['base64'])) {
				return ['b64_json' => $response['artifacts'][0]['base64']];
			} else {
				return ['error' => $response];
			}
		} catch (Exception $e) {
			return ['error' => $e->getMessage()];
		}
	}
}
