<?php

class AI_LLM_OpenAI extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * Creates a chat completion using OpenAI (LEGACY, TEXT-ONLY).
	 *
	 * Cheapest model, backward compatibility only.
	 *
	 * @method chatCompletions
	 * @return array
	 */
	public function chatCompletions(array $messages, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		$headers = array(
			"Content-Type: application/json",
			"Authorization: Bearer $apiKey"
		);

		$m = array();
		foreach ($messages as $role => $content) {
			$m[] = array(
				'role'    => $role,
				'content' => $content
			);
		}

		$payload = array(
			'model'       => Q::ifset($options, 'model', 'gpt-4o-mini'),
			'max_tokens'  => Q::ifset($options, 'max_tokens', 3000),
			'temperature' => Q::ifset($options, 'temperature', 0.5),
			'messages'    => $m
		);

		$response = Q_Utils::post(
			'https://api.openai.com/v1/chat/completions',
			$payload,
			null,
			null,
			$headers,
			Q::ifset($options, 'timeout', 300)
		);

		$decoded = json_decode($response, true);

		$content = '';
		if (is_array($decoded)
			&& isset($decoded['choices'][0]['message']['content'])
			&& is_string($decoded['choices'][0]['message']['content'])
		) {
			$content = $decoded['choices'][0]['message']['content'];
		}

		return array(
			'choices' => array(
				array(
					'message' => array(
						'content' => $content
					)
				)
			)
		);
	}

	/**
	 * Execute a model call using OpenAI's Responses API.
	 *
	 * Canonical execution path.
	 * Returns normalized semantic text.
	 *
	 * @method executeModel
	 * @param string $prompt
	 * @param array  $inputs
	 * @param array  $options
	 * @param array  &$raw Optional provider-native response
	 * @return string
	 * @throws Exception
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

		if (!empty($inputs['images']) && is_array($inputs['images'])) {
			foreach ($inputs['images'] as $binary) {
				$content[] = array(
					'type' => 'input_image',
					'image_url' => 'data:image/png;base64,' . base64_encode($binary)
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

		$response = Q_Utils::post(
			'https://api.openai.com/v1/responses',
			$payload,
			null,
			null,
			$headers,
			Q::ifset($options, 'timeout', 300)
		);

		$decoded = json_decode($response, true);
		if (!is_array($decoded)) {
			throw new Exception("Invalid Responses API envelope");
		}

		// expose raw provider response if requested
		$raw = $decoded;

		return $this->normalizeResponsesOutput($decoded);
	}

	/**
	 * Normalize OpenAI Responses output into semantic text.
	 *
	 * @param array $response
	 * @return string
	 */
	protected function normalizeResponsesOutput(array $response)
	{
		if (empty($response['output']) || !is_array($response['output'])) {
			return '';
		}

		foreach ($response['output'] as $item) {
			if (!isset($item['type']) || $item['type'] !== 'message') {
				continue;
			}

			if (empty($item['content']) || !is_array($item['content'])) {
				continue;
			}

			foreach ($item['content'] as $block) {
				if (isset($block['type'], $block['text'])
					&& $block['type'] === 'output_text'
					&& is_string($block['text'])
				) {
					return trim($block['text']);
				}
			}
		}

		return '';
	}
}
