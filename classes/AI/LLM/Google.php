<?php

class AI_LLM_Google extends AI_LLM
{
	protected $apiKey;
	protected $model;
	protected $endpoint;

	function __construct()
	{
		$this->apiKey = Q_Config::expect('AI', 'google', 'api_key');
		$this->model  = Q_Config::get(
			'AI',
			'google',
			'llm_model',
			'models/gemini-1.5-flash'
		);

		$this->endpoint =
			'https://generativelanguage.googleapis.com/v1beta/' .
			$this->model .
			':generateContent?key=' . urlencode($this->apiKey);
	}

	/**
	 * Execute a single Gemini model invocation.
	 *
	 * Contract:
	 * - Exactly ONE HTTP request
	 * - Supports text + image inputs
	 * - Returns normalized semantic text
	 * - Optional raw provider payload via &$raw
	 *
	 * @method executeModel
	 * @param {string} $prompt
	 * @param {array}  $inputs
	 * @param {array}  $options
	 * @param {array}  &$raw Optional raw provider response
	 * @return {string}
	 * @throws {Exception}
	 */
	public function executeModel($prompt, array $inputs, array $options = array(), &$raw = null)
	{
		$responseFormat = Q::ifset($options, 'response_format', null);
		$schema         = Q::ifset($options, 'json_schema', null);
		$temperature    = Q::ifset($options, 'temperature', 0.5);
		$maxTokens      = Q::ifset($options, 'max_tokens', 3000);

		/* ---------- System / schema enforcement (prompt-level only) ---------- */

		$system = '';

		if ($responseFormat === 'json_schema' && is_array($schema)) {
			$system .=
				"You are a strict JSON generator.\n" .
				"Output MUST conform exactly to this JSON Schema:\n\n" .
				json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
				"\n\nRules:\n" .
				"- Output JSON only\n" .
				"- Do not include prose, comments, or markdown\n" .
				"- Do not omit required fields\n" .
				"- Use null when uncertain\n\n";
		} elseif ($responseFormat === 'json') {
			$system .=
				"You are a strict JSON generator.\n" .
				"Output MUST be valid JSON.\n" .
				"Do not include prose, comments, or markdown\n\n";
		}

		/* ---------- Build Gemini contents ---------- */

		$parts = array(
			array('text' => $system . $prompt)
		);

		if (!empty($inputs['text'])) {
			$parts[] = array('text' => $inputs['text']);
		}

		if (!empty($inputs['images'])) {
			foreach ($inputs['images'] as $img) {
				$parts[] = array(
					'inline_data' => array(
						'mime_type' => 'image/png',
						'data'      => base64_encode($img)
					)
				);
			}
		}

		$payload = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => $parts
				)
			),
			'generationConfig' => array(
				'temperature'     => $temperature,
				'maxOutputTokens' => $maxTokens
			)
		);

		/* ---------- HTTP request ---------- */

		$ch = curl_init($this->endpoint);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS     => json_encode($payload)
		));

		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new Exception($error);
		}
		curl_close($ch);

		$decoded = json_decode($response, true);
		if (!is_array($decoded)) {
			throw new Exception('Invalid JSON returned by Gemini');
		}

		// expose raw provider payload if requested
		$raw = $decoded;

		return $this->normalizeGeminiOutput($decoded);
	}

	/**
	 * Normalize Gemini response into semantic text.
	 *
	 * @param {array} $response
	 * @return {string}
	 */
	protected function normalizeGeminiOutput(array $response)
	{
		if (empty($response['candidates'][0]['content']['parts'])) {
			return '';
		}

		$text = '';

		foreach ($response['candidates'][0]['content']['parts'] as $part) {
			if (isset($part['text']) && is_string($part['text'])) {
				$text .= $part['text'];
			}
		}

		return trim($text);
	}
}
