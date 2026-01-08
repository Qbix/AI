<?php

class AI_LLM_OpenAI extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * Creates a chat completion using OpenAI.
	 *
	 * @method chatCompletions
	 * @param {array} $messages An array of role => content, where role can be
	 *   "system", "user", or "assistant"
	 * @param {array} $options Optional parameters:
	 *   @param {string}  [$options.model="gpt-4o-mini"]
	 *   @param {integer} [$options.max_tokens=3000]
	 *   @param {float}   [$options.temperature=0.5]
	 *   @param {integer} [$options.numResults=1]
	 *   @param {integer} [$options.presencePenalty=2]
	 *   @param {integer} [$options.frequencyPenalty=2]
	 *   @param {integer} [$options.timeout=300]
	 *   @param {string}  [$options.response_format=null] If "json", returns JSON
	 *   @param {callable}[$options.callback] Optional callback (batch-safe)
	 *
	 * @return {array} Decoded OpenAI response or error structure
	 */
	function chatCompletions(array $messages, $options = array())
	{
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		$userCallback = Q::ifset($options, 'callback', null);

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
			'model'             => Q::ifset($options, 'model', 'gpt-4o-mini'),
			'max_tokens'        => Q::ifset($options, 'max_tokens', 3000),
			'temperature'       => Q::ifset($options, 'temperature', 0.5),
			'n'                 => Q::ifset($options, 'numResults', 1),
			'presence_penalty'  => Q::ifset($options, 'presencePenalty', 2),
			'frequency_penalty' => Q::ifset($options, 'frequencyPenalty', 2),
			'messages'          => $m
		);

		if (Q::ifset($options, 'response_format', null) === 'json') {
			$payload['response_format'] = 'json';
			$headers[] = "OpenAI-Beta: response_format=json";
		}

		$timeout = Q_Config::get('AI', 'openAI', 'timeout', 300);

		// Result container (batch-safe)
		$result = array(
			'data'  => null,
			'error' => null
		);

		// Shared response handler
        $callback = function ($indexOrHandle, $response) use (&$result, $userCallback) {

            if (!is_string($response) || $response === '') {
                $result['error'] = 'Empty or invalid response';
            } else {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    $result['data'] = $data;
                } else {
                    $result['error'] = 'Invalid JSON from OpenAI';
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

		// Batch mode: callback will run later
		if (is_int($json)) {
			return array();
		}

		// Non-batch: callback already executed
		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result['data'];
	}
}