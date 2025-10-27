<?php

class AI_Image_Google extends AI_Image implements AI_Image_Interface
{
	/**
	 * Generates an image via Vertex (Nano Banana) through our Node proxy.
	 *
	 * @param string $prompt
	 * @param array $options ['photo' => base64image, 'format'=>'png']
	 */
	public static function generate($prompt, $options = [])
	{
		$proxyUrl = Q_Config::expect('AI', 'google', 'url');
		$clientId = Q_Config::expect('AI', 'google', 'clientId');
		$secret   = Q_Config::expect('AI', 'google', 'secret');

		$timestamp = time();
		$body = [
			'prompt' => $prompt,
			'photo'  => Q::ifset($options, 'photo')
		];
		$rawBody = json_encode($body);

		$signature = hash_hmac('sha256', $clientId . $timestamp . $rawBody, $secret);

		$headers = [
			"X-Client-ID: $clientId",
			"X-Timestamp: $timestamp",
			"X-Signature: $signature",
			"Content-Type: application/json"
		];

		$response = Q_Utils::post(
			rtrim($proxyUrl, '/') . '/avatar',
			$rawBody,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 60)
		);

		if ($response === false) {
			return ['error' => 'Proxy unreachable'];
		}

		// If image, return binary
		if (strpos(substr($response, 0, 32), '{') === false) {
			return ['data' => $response, 'format' => 'png'];
		}

		// Otherwise parse JSON error
		$json = json_decode($response, true);
		return $json ?: ['error' => 'Invalid proxy response'];
	}
}
