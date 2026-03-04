<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_LLM_Aws extends AI_LLM
{
	protected $client;
	protected $modelId;

	function __construct()
	{
		$this->client = new BedrockRuntimeClient(array(
			'region'  => Q_Config::expect('AI', 'aws', 'region'),
			'version' => 'latest'
		));

		$defaultAwsModel = Q_Config::get(
			'AI',
			'aws',
			'llm_model_id',
			'anthropic.claude-3-sonnet-20240229-v1:0'
		);

		$this->modelId = Q_Config::get(
			'AI',
			'llm',
			'models',
			'aws',
			$defaultAwsModel
		);
	}

	/**
	 * Execute a single model invocation against AWS Bedrock (Claude).
	 *
	 * Supports callback mode for batching compatibility.
	 */
	public function executeModel($instructions, array $inputs, array $options = array(), &$raw = null)
	{
		$modelId = Q::ifset($options, 'model', $this->modelId);

		$responseFormat = Q::ifset($options, 'response_format', null);
		$schema         = Q::ifset($options, 'json_schema', null);

		$temperature = Q::ifset($options, 'temperature', 0.5);
		$maxTokens   = Q::ifset($options, 'max_tokens', 3000);

		$userCallback = Q::ifset($options, 'callback', null);

		/* ---------- JSON / schema enforcement ---------- */

		$systemPrompt = '';

		if ($responseFormat === 'json_schema' && is_array($schema)) {
			$systemPrompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON and MUST conform exactly to this JSON Schema:\n\n" .
				json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
				"\n\nRules:\n" .
				"- Output JSON only\n" .
				"- Do not include prose, comments, or markdown\n" .
				"- Do not omit required fields\n" .
				"- Use null when a value is unknown\n\n";
		} elseif ($responseFormat === 'json') {
			$systemPrompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON.\n" .
				"Do not include prose, comments, or markdown\n\n";
		}

		if (!empty($instructions)) {
			$systemPrompt .= $instructions;
		}

		/* ---------- Build Claude messages ---------- */

		$messages = array();

		if (!empty($options['messages']) && is_array($options['messages'])) {

			foreach ($options['messages'] as $msg) {

				$role = Q::ifset($msg, 'role', 'user');
				$content = Q::ifset($msg, 'content', '');

				if (is_string($content)) {
					$content = array(
						array(
							'type' => 'text',
							'text' => $content
						)
					);
				}

				// Claude does not support tool role directly
				if ($role === 'tool') {
					$role = 'assistant';
				}

				$messages[] = array(
					'role' => $role,
					'content' => $content
				);
			}

		} else {

			/* ---------- Legacy behaviour ---------- */

			$userText = '';

			if (!empty($inputs['text'])) {
				$userText .= $inputs['text'] . "\n\n";
			}

			// Claude has no vision support
			if (!empty($inputs['images'])) {
				$userText .= "[Image inputs omitted]\n\n";
			}

			if (!empty($userText)) {

				$messages[] = array(
					'role' => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $userText
						)
					)
				);
			}
		}

		/* ---------- Payload ---------- */

		$payload = array(
			'anthropic_version' => 'bedrock-2023-05-31',
			'system'            => $systemPrompt,
			'messages'          => $messages,
			'max_tokens'        => $maxTokens,
			'temperature'       => $temperature,
			'top_k'             => 250,
			'top_p'             => 0.999
		);

		$result = array(
			'text'  => '',
			'raw'   => null,
			'error' => null
		);

		try {

			$response = $this->client->invokeModel(array(
				'modelId'     => $modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json'
			));

			$decoded = json_decode($response['body']->getContents(), true);

			if (!is_array($decoded)) {
				throw new Exception('Invalid JSON returned by Bedrock');
			}

			$result['raw']  = $decoded;
			$result['text'] = $this->normalizeClaudeOutput($decoded);

			$raw = $decoded;

		} catch (Exception $e) {

			$result['error'] = $e->getMessage();
		}

		/* ---------- Callback mode ---------- */

		if ($userCallback && is_callable($userCallback)) {

			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}

			return '';
		}

		/* ---------- Sync mode ---------- */

		if ($result['error']) {
			throw new Exception($result['error']);
		}

		return $result['text'];
	}

	/**
	 * Normalize Claude output into semantic text.
	 */
	protected function normalizeClaudeOutput(array $response)
	{
		if (isset($response['completion']) && is_string($response['completion'])) {
			return trim($response['completion']);
		}

		if (!empty($response['content'][0]['text'])) {
			return trim($response['content'][0]['text']);
		}

		return '';
	}
}