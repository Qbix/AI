<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_LLM_AWS extends AI_LLM implements AI_LLM_Interface
{
	protected $client;
	protected $modelId;

	function __construct()
	{
		$this->client = new BedrockRuntimeClient(array(
			'region'  => Q_Config::expect('AI', 'aws', 'region'),
			'version' => 'latest',
		));

		$this->modelId = Q_Config::get(
			'AI',
			'aws',
			'llm_model_id',
			'anthropic.claude-3-sonnet-20240229-v1:0'
		);
	}

	function chatCompletions(array $messages, $options = array())
	{
		$userCallback   = Q::ifset($options, 'callback', null);
		$responseFormat = Q::ifset($options, 'response_format', null);
		$schema         = Q::ifset($options, 'json_schema', null);

		$prompt = '';

		/* ---------- Schema / JSON instructions (prompt-level only) ---------- */

		if ($responseFormat === 'json_schema' && is_array($schema)) {
			$prompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON and MUST conform exactly to this JSON Schema:\n\n" .
				json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
				"\n\n" .
				"Rules:\n" .
				"- Output JSON only\n" .
				"- Do not include prose, comments, or markdown outside the JSON\n" .
				"- Do not omit required fields\n" .
				"- Use null when a value is unknown\n\n";
		} elseif ($responseFormat === 'json') {
			$prompt .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON.\n" .
				"Do not include prose, comments, or markdown outside the JSON.\n\n";
		}

		/* ---------- Convert normalized messages ---------- */

		foreach ($messages as $role => $content) {
			if ($role === 'system') {
				$prompt .= $content . "\n\n";
				continue;
			}

			$prompt .= ucfirst($role) . ":\n";

			if (is_array($content)) {
				foreach ($content as $block) {
					if (!is_array($block) || empty($block['type'])) {
						continue;
					}

					switch ($block['type']) {
						case 'text':
							$prompt .= $block['text'] . "\n";
							break;

						case 'image_url':
							// Claude via Bedrock has no vision support
							$prompt .= "[Image omitted]\n";
							break;
					}
				}
			} else {
				$prompt .= (string)$content . "\n";
			}

			$prompt .= "\n";
		}

		$prompt .= "Assistant:";

		$payload = array(
			'prompt'               => $prompt,
			'max_tokens_to_sample' => Q::ifset($options, 'max_tokens', 3000),
			'temperature'          => Q::ifset($options, 'temperature', 0.5),
			'top_k'                => 250,
			'top_p'                => 0.999,
			'stop_sequences'       => array("\n\nHuman:", "\n\nAssistant:")
		);

		$result = array(
			'data'  => null,
			'error' => null
		);

		try {
			$response = $this->client->invokeModel(array(
				'modelId'     => $this->modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			));

			$decoded = json_decode($response['body']->getContents(), true);

			if (!is_array($decoded)) {
				$result['error'] = 'Invalid JSON returned by Bedrock';
			} else {
				$result['data'] = $decoded;
			}
		} catch (Exception $e) {
			$result['error'] = $e->getMessage();
		}

		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $result);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		if ($result['error']) {
			return array('error' => $result['error']);
		}

		return $result['data'];
	}
}