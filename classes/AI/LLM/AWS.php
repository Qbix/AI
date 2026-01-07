<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * AWS Bedrock LLM adapter.
 *
 * Provides chat completion functionality via Anthropic models hosted on
 * AWS Bedrock.
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
	 * Creates a chat completion using AWS Bedrock.
	 *
	 * Supported options:
	 * - max_tokens: int (default 3000)
	 * - temperature: float (default 0.5)
	 * - callback: callable (optional)
	 *
	 * The callback, if provided, will be called with the final result array.
	 *
	 * @method chatCompletions
	 * @param {array} $messages Array of role => content pairs
	 * @param {array} $options Optional parameters
	 * @return {array} OpenAI-compatible response structure
	 */
	function chatCompletions(array $messages, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		$prompt = '';
		foreach ($messages as $role => $content) {
			if ($role === 'system') {
				continue;
			}
			$prompt .= ucfirst($role) . ": " . $content . "\n";
		}
		$prompt .= "Assistant:";

		$payload = array(
			'prompt'                => $prompt,
			'max_tokens_to_sample'  => Q::ifset($options, 'max_tokens', 3000),
			'temperature'           => Q::ifset($options, 'temperature', 0.5),
			'top_k'                 => 250,
			'top_p'                 => 0.999,
			'stop_sequences'        => array("\n\nHuman:", "\n\nAssistant:")
		);

		try {
			$result = $this->client->invokeModel(array(
				'modelId'     => $this->modelId,
				'body'        => json_encode($payload),
				'contentType' => 'application/json',
				'accept'      => 'application/json',
			));

			$body = json_decode($result['body']->getContents(), true);

			$response = array(
				'choices' => array(
					array(
						'message' => array(
							'content' => Q::ifset($body, 'completion', '')
						)
					)
				)
			);
		} catch (Exception $e) {
			$response = array(
				'error' => $e->getMessage()
			);
		}

		// Optional user callback
		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $response);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		return $response;
	}
}
