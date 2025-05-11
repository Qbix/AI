<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AI_LLM_AWS extends AI_LLM implements AI_LLM_Interface
{
    protected $client;
    protected $modelId;

    function __construct()
    {
        $this->client = new BedrockRuntimeClient([
            'region' => Q_Config::expect('AI', 'aws', 'region'),
            'version' => 'latest',
        ]);
        $this->modelId = Q_Config::get('AI', 'aws', 'llm_model_id', 'anthropic.claude-3-sonnet-20240229-v1:0');
    }

    function chatCompletions(array $messages, $options = array())
    {
        $prompt = '';
        foreach ($messages as $role => $content) {
            if ($role === 'system') continue;
            $prompt .= ucfirst($role) . ": " . $content . "\n";
        }
        $prompt .= "Assistant:";

        $payload = [
            'prompt' => $prompt,
            'max_tokens_to_sample' => Q::ifset($options, 'max_tokens', 3000),
            'temperature' => Q::ifset($options, 'temperature', 0.5),
            'top_k' => 250,
            'top_p' => 0.999,
            'stop_sequences' => ["\n\nHuman:", "\n\nAssistant:"],
        ];

        $result = $this->client->invokeModel([
            'modelId' => $this->modelId,
            'body' => json_encode($payload),
            'contentType' => 'application/json',
            'accept' => 'application/json',
        ]);

        $body = json_decode($result['body']->getContents(), true);
        return [
            'choices' => [[
                'message' => ['content' => $body['completion']]
            ]]
        ];
    }
}
