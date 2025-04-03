<?php

class AI_LLM_OpenAI extends AI_LLM implements AI_LLM_Interface
{
    /**
     * @method chatCompletions
     * @param {array} $messages An array of role => content, where role can be "system", "user", "assistant"
     * @param {array} $options
     * @param {string} [$options.model="gpt-4o-mini"] You can override which chat model to use
     * @param {integer} [$options.max_tokens=3000] Maximum number of tokens to return
     * @param {integer} [$options.temperature=0.9] How many results to return
     * @param {integer} [$options.numResults=1] How many results to return
     * @param {integer} [$options.presencePenalty=2]
     * @param {integer} [$options.frequencyPenalty=2]
     * @param {integer} [$options.timeout=300]
     * @return {array} Contains "errors" or "choices" keys
     */
    function chatCompletions(array $messages, $options = array())
    {
        $apiKey = Q_Config::expect('AI', 'openAI', 'key');
        $headers = array(
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        );
        $m = array();
        foreach ($messages as $role => $content) {
            $m[] = compact('role', 'content');
        }
        $payload = array(
            "model" => Q::ifset($options, 'model', 'gpt-4o-mini'),
            "max_tokens" => Q::ifset($options, 'max_tokens', 3000),
            "temperature" => Q::ifset($options, 'temperature', 0.9),
            "n" => Q::ifset($options, 'numResults', 1),
            "presence_penalty" => Q::ifset($options, 'presencePenalty', 2),
            "frequency_penalty" => Q::ifset($options, 'frequencyPenalty', 2),
            "messages" => $formatted
        );
        $timeout = Q_Config::get('AI', 'openAI', 'timeout', 300);
        $json = Q_Utils::post(
            'https://api.openai.com/v1/chat/completions', 
            $payload, null, null, $headers,
            Q::ifset($options, 'timeout', $timeout)
        );
        return json_decode($json, true);
    }
    
}