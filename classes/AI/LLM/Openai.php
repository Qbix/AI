<?php

class AI_LLM_Openai extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * Execute a model call using OpenAI's Responses API.
	 */
	public function executeModel($prompt, array $inputs, array $options = array(), &$raw = null)
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		$headers = array(
			"Content-Type: application/json",
			"Authorization: Bearer $apiKey"
		);

		$content = array(
			array(
				'type' => 'input_text',
				'text' => $prompt
			)
		);

		if (!empty($inputs['text'])) {
			$content[] = array(
				'type' => 'input_text',
				'text' => $inputs['text']
			);
		}

		/**
		 * Multimodal images
		 * - Preserve JPEG / PNG
		 * - Never send WebP
		 * - Correct MIME in data URL
		 */
		if (!empty($inputs['images']) && is_array($inputs['images'])) {
			foreach ($inputs['images'] as $binary) {

				$raw = Q_Utils::toRawBinary($binary);
				if ($raw === false) {
					continue;
				}

				$img = @imagecreatefromstring($raw);
				if (!$img) {
					continue;
				}

				$hasAlpha = $this->imageHasAlpha($img);

				if ($hasAlpha) {
					ob_start();
					imagepng($img);
					$data = ob_get_clean();
					$mime = 'image/png';
				} else {
					ob_start();
					imagejpeg($img, null, 85);
					$data = ob_get_clean();
					$mime = 'image/jpeg';
				}

				imagedestroy($img);

				$content[] = array(
					'type' => 'input_image',
					'image_url' => 'data:' . $mime . ';base64,' . base64_encode($data)
				);
			}
		}

		$payload = array(
			'model' => Q::ifset($options, 'model', 'gpt-4.1-mini'),
			'input' => array(
				array(
					'role'    => 'user',
					'content' => $content
				)
			),
			'max_output_tokens' => Q::ifset($options, 'max_tokens', 3000),
			'temperature'      => Q::ifset($options, 'temperature', 0.5)
		);

		$callback = Q::ifset($options, 'callback', null);

		$response = Q_Utils::post(
			'https://api.openai.com/v1/responses',
			$payload,
			null,
			null,
			$headers,
			Q::ifset($options, 'timeout', 300),
			$callback
				? function ($info, $body) use ($callback, &$raw) {

					$decoded = json_decode($body, true);
					if (!is_array($decoded)) {
						throw new Exception("Invalid Responses API envelope");
					}

					$raw = $decoded;
					$result = $this->normalizeResponsesOutput($decoded);

					call_user_func($callback, $result, $decoded, $info);
				}
				: null
		);

		// Batched mode
		if ($callback) {
			return $response;
		}

		// Non-batched mode
		$decoded = json_decode($response, true);
		if (!is_array($decoded)) {
			throw new Exception("Invalid Responses API envelope");
		}

		$raw = $decoded;
		return $this->normalizeResponsesOutput($decoded);
	}

	/**
	 * Detect alpha channel
	 */
	protected function imageHasAlpha($img)
	{
		if (!imageistruecolor($img)) return false;
		return imagecolortransparent($img) >= 0;
	}

	/**
	 * Normalize OpenAI Responses output into semantic text.
	 */
	protected function normalizeResponsesOutput(array $response)
	{
		if (empty($response['output']) || !is_array($response['output'])) {
			return '';
		}

		foreach ($response['output'] as $item) {
			if (($item['type'] ?? null) !== 'message') {
				continue;
			}

			if (empty($item['content']) || !is_array($item['content'])) {
				continue;
			}

			foreach ($item['content'] as $block) {
				if (
					($block['type'] ?? null) === 'output_text'
					&& isset($block['text'])
					&& is_string($block['text'])
				) {
					return trim($block['text']);
				}
			}
		}

		return '';
	}
}
