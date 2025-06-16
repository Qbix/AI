<?php

class AI_Image_OpenAI extends AI_Image implements AI_Image_Interface
{
    /**
     * @method generate
     * @param {string} $prompt The prompt to generate an image from
     * @param {array} $options Optional settings:
     * @param {string} [$options.model="dall-e-3"] The model to use, e.g. "dall-e-3" or "dall-e-2"
     * @param {string} [$options.response_format="url"] Either "url" or "b64_json" for base64
     * @param {string} [$options.size="1024x1024"] The desired image size, e.g. "1024x1024"
     * @param {string} [$options.quality="standard"] The quality level, e.g. "standard" or "hd"
     * @return {array} Either ['url' => ...] or ['b64_json' => ...] or ['error' => ...]
     */
    public static function generate($prompt, $options = array())
    {
        $apiKey = Q_Config::expect('AI', 'openAI', 'key');
        $headers = array(
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        );

        $payload = array(
            "model" => Q::ifset($options, 'model', 'dall-e-3'),
            "prompt" => $prompt,
            "n" => 1,
            "response_format" => Q::ifset($options, 'response_format', 'url'),
            "size" => Q::ifset($options, 'size', '1024x1024'),
            "quality" => Q::ifset($options, 'quality', 'standard')
        );

        $timeout = Q_Config::get('AI', 'openAI', 'timeout', 60);
        $json = Q_Utils::post(
            'https://api.openai.com/v1/images/generations',
            $payload, null, null, $headers,
            Q::ifset($options, 'timeout', $timeout)
        );

        $response = json_decode($json, true);
        if (isset($response['data'][0])) {
            return $response['data'][0];
        } else {
            return array("error" => $response);
        }
    }
}