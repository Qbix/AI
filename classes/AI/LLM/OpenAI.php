<?php

class AI_LLM_OpenAI extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * Creates a chat completion using OpenAI.
	 *
	 * @method chatCompletions
	 * @param {array} $messages An array of role => content
	 * @param {array} $options Optional parameters:
	 *   @param {string}  [$options.model="gpt-5.2-instant"]
	 *   @param {integer} [$options.max_tokens=3000]
	 *   @param {float}   [$options.temperature=0.5]
	 *   @param {integer} [$options.numResults=1]
	 *   @param {integer} [$options.presencePenalty=0]
	 *   @param {integer} [$options.frequencyPenalty=0]
	 *   @param {integer} [$options.timeout=300]
	 *   @param {string}  [$options.response_format=null] "json" | "json_schema"
	 *   @param {array}   [$options.json_schema] Required if response_format=json_schema
	 *   @param {callable}[$options.callback]
	 *
	 * @return {array}
	 */
	function chatCompletions(array $messages, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');
		$userCallback = Q::ifset($options, 'callback', null);

		$headers = array(
			"Content-Type: application/json",
			"Authorization: Bearer $apiKey"
		);

		// Normalize messages
		$m = array();
		foreach ($messages as $role => $content) {
			$m[] = array(
				'role'    => $role,
				'content' => $content
			);
		}

		$payload = array(
			'model'             => Q::ifset($options, 'model', 'gpt-5.2-instant'),
			'max_tokens'        => Q::ifset($options, 'max_tokens', 3000),
			'temperature'       => Q::ifset($options, 'temperature', 0.5),
			'n'                 => Q::ifset($options, 'numResults', 1),
			'presence_penalty'  => Q::ifset($options, 'presencePenalty', 0),
			'frequency_penalty' => Q::ifset($options, 'frequencyPenalty', 0),
			'messages'          => $m
		);

		// Structured output handling
		$responseFormat = Q::ifset($options, 'response_format', null);

		if ($responseFormat === 'json_schema') {
			$schema = Q::ifset($options, 'json_schema', null);
			if (!$schema || !is_array($schema)) {
				throw new Exception("json_schema must be provided when response_format=json_schema");
			}
			$payload['response_format'] = array(
				'type' => 'json_schema',
				'json_schema' => $schema
			);
		}
		// response_format === 'json' is prompt-enforced only (no payload change)

		$timeout = Q_Config::get('AI', 'openAI', 'timeout', 300);

		$result = array(
			'data'  => null,
			'error' => null
		);

		// Shared response handler (batch-safe)
		$callback = function ($indexOrHandle, $response) use (&$result, $userCallback) {

			if (!is_string($response) || $response === '') {
				$result['error'] = 'Empty or invalid response';
			} else {
				$envelope = json_decode($response, true);
				if (!is_array($envelope)) {
					$result['error'] = 'Invalid JSON envelope from OpenAI';
				} else {
					$content =
						$envelope['choices'][0]['message']['content'] ?? null;

					if (!is_string($content)) {
						$result['error'] = 'Missing message content';
					} else {
						$decoded = json_decode($content, true);
						if (is_array($decoded)) {
							$result['data'] = $decoded;
						} else {
							$result['error'] = 'Invalid JSON from model content';
						}
					}
				}
			}

			if ($userCallback) {
				$userCallback($result);
			}
		};

		$json = Q_Utils::post(
			'https://api.openai.com/v1/chat/completions',
			$payload,
			null,
			null,
			$headers,
			Q::ifset($options, 'timeout', $timeout),
			$callback
		);

		// Batch mode
		if (is_int($json)) {
			return array();
		}

		// Non-batch
		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result['data'];
	}
}