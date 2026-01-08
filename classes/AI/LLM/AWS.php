<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * AWS Bedrock LLM adapter.
 *
 * Provides chat completion functionality via Anthropic models hosted on
 * AWS Bedrock.
 *
 * This adapter is transport-only:
 * - It serializes normalized messages
 * - Invokes Bedrock
 * - Returns the decoded response structure
 *
 * It MUST NOT:
 * - Extract text or JSON payloads
 * - Apply policies or accumulation
 *
 * @class AI_LLM_AWS
 * @extends AI_LLM
 * @implements AI_LLM_Interface
 */
class AI_LLM_AWS extends AI_LLM implements AI_LLM_Interface
{
	/**
	 * @property client
	 * @type BedrockRuntimeClient
	 * @protected
	 */
	protected $client;

	/**
	 * @property modelId
	 * @type string
	 * @protected
	 */
	protected $modelId;

	/**
	 * Constructor.
	 *
	 * Initializes the AWS Bedrock runtime client and model ID.
	 *
	 * @method __construct
	 */
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

	/**
	 * Creates a chat completion using AWS Bedrock (Anthropic).
	 *
	 * Supported options:
	 * - max_tokens
	 * - temperature
	 * - callback (batch-safe)
	 *
	 * @method chatCompletions
	 * @param {array} $messages Normalized role => content structure
	 * @param {array} $options Optional parameters
	 * @return {array} Decoded Bedrock response or error structure
	 */
	function chatCompletions(array $messages, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		/**
		 * Convert normalized messages into Anthropic prompt format.
		 *
		 * Bedrock (Anthropic) does not yet support OpenAI-style message arrays,
		 * so we linearize content blocks conservatively.
		 */
		$prompt = '';
		foreach ($messages as $role => $content) {
			if ($role === 'system') {
				// System prompt is prepended implicitly
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
							// Bedrock Anthropic does NOT support images yet.
							// Explicitly ignore but preserve determinism.
							$prompt .= "[Image omitted]\n";
							break;
					}
				}
			} else {
				// Fallback: treat as plain text
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

		// Optional batch-safe callback
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
