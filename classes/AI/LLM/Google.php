<?php

/**
 * Google Gemini LLM adapter.
 *
 * Supports multimodal (text + images).
 *
 * @class AI_LLM_Google
 * @extends AI_LLM
 * @implements AI_LLM_Interface
 */
class AI_LLM_Google extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * @method chatCompletions
	 * @param {array} $messages Normalized messages
	 * @param {array} $options
	 * @return {array}
	 */
	function chatCompletions(array $messages, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'google', 'key');
		$model  = Q::ifset($options, 'model', 'gemini-1.5-pro');

		$endpoint =
			"https://generativelanguage.googleapis.com/v1beta/models/"
			. $model
			. ":generateContent?key="
			. urlencode($apiKey);

		// Convert normalized messages → Gemini contents
		$contents = array();

		foreach ($messages as $role => $content) {
			$parts = array();

			// system is folded into user prompt for Gemini
			if ($role === 'system') {
				$parts[] = array(
					'text' => $content
				);
			} else {
				foreach ($content as $block) {
					if ($block['type'] === 'text') {
						$parts[] = array(
							'text' => $block['text']
						);
					} elseif ($block['type'] === 'image_url') {
						// data:image/png;base64,...
						$parts[] = array(
							'inline_data' => array(
								'mime_type' => 'image/png',
								'data' => preg_replace(
									'/^data:image\/\w+;base64,/',
									'',
									$block['image_url']['url']
								)
							)
						);
					}
				}
			}

			if (!empty($parts)) {
				$contents[] = array(
					'role'  => 'user',
					'parts' => $parts
				);
			}
		}

		$payload = array(
			'contents' => $contents,
			'generationConfig' => array(
				'temperature' => Q::ifset($options, 'temperature', 0.5),
				'maxOutputTokens' => Q::ifset($options, 'max_tokens', 3000),
			)
		);

		$response = Q_Utils::post(
			$endpoint,
			$payload,
			null,
			null,
			array("Content-Type: application/json"),
			Q::ifset($options, 'timeout', 300)
		);

		if (!is_string($response) || $response === '') {
			return array('error' => 'Empty response from Google Gemini');
		}

		$data = json_decode($response, true);
		if (!is_array($data)) {
			return array('error' => 'Invalid JSON from Google Gemini');
		}

		// Normalize to OpenAI-like structure
		$text = Q::ifset(
			$data,
			'candidates',
			0,
			'content',
			'parts',
			0,
			'text',
			''
		);

		return array(
			'choices' => array(
				array(
					'message' => array(
						'content' => $text
					)
				)
			)
		);
	}
}