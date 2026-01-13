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

		$this->modelId = Q_Config::get(
			'AI',
			'aws',
			'llm_model_id',
			'anthropic.claude-3-sonnet-20240229-v1:0'
		);
	}

	/**
	 * Execute a single model invocation against AWS Bedrock (Claude).
	 *
	 * Supports callback mode for batching compatibility.
	 */
	public function executeModel($prompt, array $inputs, array $options = array(), &$raw = null)
	{
		$responseFormat = Q::ifset($options, 'response_format', null);
		$schema         = Q::ifset($options, 'json_schema', null);

		$temperature = Q::ifset($options, 'temperature', 0.5);
		$maxTokens   = Q::ifset($options, 'max_tokens', 3000);

		$userCallback = Q::ifset($options, 'callback', null);

		$fullPrompt = '';

		/* ---------- JSON / schema enforcement ---------- */

		if ($responseFormat === 'json_schema' && is_array($schema)) {
			$fullPrompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON and MUST conform exactly to this JSON Schema:\n\n" .
				json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
				"\n\nRules:\n" .
				"- Output JSON only\n" .
				"- Do not include prose, comments, or markdown\n" .
				"- Do not omit required fields\n" .
				"- Use null when a value is unknown\n\n";
		} elseif ($responseFormat === 'json') {
			$fullPrompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON.\n" .
				"Do not include prose, comments, or markdown\n\n";
		}

		/* ---------- Core prompt ---------- */

		$fullPrompt .= $prompt . "\n\n";

		if (!empty($inputs['text'])) {
			$fullPrompt .= $inputs['text'] . "\n\n";
		}

		// Claude has no vision support
		if (!empty($inputs['images'])) {
			$fullPrompt .= "[Image inputs omitted]\n\n";
		}

		$fullPrompt .= "Assistant:";

		$payload = array(
			'prompt'               => $fullPrompt,
			'max_tokens_to_sample' => $maxTokens,
			'temperature'          => $temperature,
			'top_k'                => 250,
			'top_p'                => 0.999,
			'stop_sequences'       => array("\n\nHuman:", "\n\nAssistant:")
		);

		$result = array(
			'text'  => '',
			'raw'   => null,
			'error' => null
		);

		try {
			$response = $this->client->invokeModel(array(
				'modelId'     => $this->modelId,
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

		return '';
	}
}
