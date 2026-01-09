<?php
/**
 * Base interfaces and abstractions for Large Language Model (LLM) adapters.
 *
 * This file defines a **provider-agnostic execution contract** that cleanly
 * supports OpenAI, Google Gemini, and AWS Bedrock (Claude) without leaking
 * provider-specific semantics into application code.
 *
 * Design rules:
 * - executeModel() is the ONLY core primitive.
 * - Exactly ONE RPC per executeModel() call.
 * - chatCompletions() exists purely for legacy compatibility.
 *
 * No retries, no batching, no streaming, no policy logic here.
 */

/**
 * Interface AI_LLM_Interface
 */
interface AI_LLM_Interface
{
	/**
	 * Legacy chat-style invocation.
	 *
	 * This exists only to support older code paths that expect a
	 * Chat Completions–style API.
	 *
	 * Implementations SHOULD translate this into exactly one
	 * executeModel() call internally.
	 *
	 * @method chatCompletions
	 *
	 * @param {array} $messages
	 *   Normalized chat messages:
	 *   {
	 *     system: string,
	 *     user: array<{
	 *       type: "text" | "image_url",
	 *       text?: string,
	 *       image_url?: { url: string }
	 *     }>
	 *   }
	 *
	 * @param {array} $options
	 *   Provider-specific options (model, temperature, max_tokens, etc.)
	 *
	 * @return {array}
	 *   Chat-style envelope:
	 *   {
	 *     choices: [
	 *       { message: { content: string } }
	 *     ]
	 *   }
	 */
	function chatCompletions(array $messages, $options = array());

	/**
	 * Execute a single model invocation.
	 *
	 * This is the **core abstraction** used by all modern code.
	 * Provider adapters MUST implement this.
	 *
	 * This method:
	 * - Performs exactly ONE RPC to the underlying model provider
	 * - Does NOT call chatCompletions()
	 * - Does NOT retry, batch, stream, or interpret output
	 *
	 * @method executeModel
	 *
	 * @param {string} $prompt
	 *   Fully constructed instruction block.
	 *   This is provider-agnostic text that frames the task
	 *   (system prompt, rules, schema instructions, etc.).
	 *
	 * @param {array} $inputs
	 *   Multimodal inputs passed to the model.
	 *
	 *   Canonical structure (all keys optional unless stated):
	 *
	 *   {
	 *     text: string|null,
	 *       - Primary textual input.
	 *       - Used by all providers.
	 *
	 *     images: array<binary>,
	 *       - Raw binary image data (PNG/JPEG).
	 *       - Supported by OpenAI (Responses API) and Google Gemini.
	 *       - NOT supported by AWS Bedrock Claude (must be ignored or stubbed).
	 *
	 *     pdfs: array<binary>,
	 *       - Optional document binaries.
	 *       - Currently supported only by some Gemini endpoints.
	 *       - Safe to ignore for providers that do not support it.
	 *
	 *     audio: array<binary>,
	 *       - Future-facing input for speech/audio models.
	 *       - Not currently used by default adapters.
	 *
	 *     video: array<binary>,
	 *       - Future-facing input for video-capable models.
	 *       - Not currently used by default adapters.
	 *
	 *     artifacts: array<mixed>,
	 *       - Provider-specific or experimental payloads.
	 *       - Examples: tool call context, intermediate state,
	 *         or externally-produced embeddings.
	 *       - Adapters MAY ignore this entirely.
	 *   }
	 *
	 * @param {array} $options
	 *   Provider-specific execution options, such as:
	 *   - model
	 *   - temperature
	 *   - max_tokens
	 *   - response_format ("json", "json_schema")
	 *   - json_schema
	 *   - timeout
	 *
	 * @return {mixed}
	 *   Raw decoded model output:
	 *   - string (most text generations)
	 *   - array  (structured JSON output)
	 */
	function executeModel($prompt, array $inputs, array $options = array());
}

/**
 * Abstract base class AI_LLM
 */
abstract class AI_LLM implements AI_LLM_Interface
{
	/**
     * Legacy chat-style adapter.
     *
     * @deprecated
     * This method exists ONLY for backward compatibility with
     * chat-completions-style APIs.
     *
     * New code MUST call executeModel() directly.
     *
     * Internally, this method:
     * - Flattens chat messages into a single prompt + inputs
     * - Calls executeModel() exactly once
     * - Wraps the raw result in a chat-style envelope
     *
     * No provider-specific behavior lives here.
     */
	public function chatCompletions(array $messages, $options = array())
	{
		$prompt = '';
		$inputs = array(
			'text'   => null,
			'images' => array()
		);

		foreach ($messages as $role => $content) {
			if ($role === 'system') {
				$prompt .= $content . "\n\n";
				continue;
			}

			if ($role === 'user' && is_array($content)) {
				foreach ($content as $block) {
					if (!is_array($block) || empty($block['type'])) {
						continue;
					}

					if ($block['type'] === 'text') {
						$inputs['text'] =
							(string)Q::ifset($inputs, 'text', '') . $block['text'];
					} elseif ($block['type'] === 'image_url') {
						$inputs['images'][] = base64_decode(
							preg_replace(
								'#^data:image/\w+;base64,#',
								'',
								$block['image_url']['url']
							)
						);
					}
				}
			}
		}

		$result = $this->executeModel($prompt, $inputs, $options);

		return array(
			'choices' => array(
				array(
					'message' => array(
						'content' => is_string($result)
							? $result
							: json_encode($result)
					)
				)
			)
		);
	}

	/**
	 * Execute a single model invocation.
	 *
	 * Concrete adapters MUST implement this.
	 *
	 * @method executeModel
	 * @abstract
	 */
	abstract public function executeModel($prompt, array $inputs, array $options = array());

/**
	 * Process multimodal inputs through observation evaluation.
	 *
	 * Exactly ONE model call is made.
	 * The model produces ONLY per-artifact observations.
	 *
	 * @method process
	 * @param {array} $inputs
	 *   Arbitrary multimodal inputs. Examples:
	 *   {
	 *     text: string,
	 *     images: array<binary>,
	 *     pdfs: array<binary>,
	 *     artifacts: array<mixed>
	 *   }
	 * @param {array} $observations
	 *   observationName => {
	 *     promptClause: string,
	 *     fieldNames: array<string>,
	 *     example?: array<string,mixed>
	 *   }
	 * @param {array} [$interpolate]
	 *   Optional placeholder map passed to Q::interpolate()
	 * @return {array}
	 *   Observation JSON emitted by the model.
	 * @throws {Exception}
	 */
	public function process(array $inputs, array $observations, array $interpolate = array(), array $options = array())
	{
		if (empty($observations)) {
			throw new Exception("Nothing to process: no observations defined");
		}

		$o = self::promptFromObservations($observations);

		if (empty($o['clauses'])) {
			throw new Exception("No valid observation clauses generated");
		}

		$prompt =
			"You are an automated semantic processor.\n\n" .
			"Rules:\n" .
			"- Output MUST be valid JSON\n" .
			"- Do not include comments or prose\n" .
			"- Do not omit fields\n" .
			"- Use null when uncertain\n" .
			"- Arrays must respect stated limits\n" .
			"- Numeric values must be within stated ranges\n" .
			"- If uncertainty is high for any field, lower the confidence score accordingly\n\n" .
			"Inputs are referenced ONLY by the names provided in the text.\n" .
			"Do not infer meaning from order, index, or file type.\n\n" .
			"OBSERVATIONS:\n" . implode("\n", $o['clauses']) . "\n\n" .
			"Return ONLY valid JSON matching this schema exactly:\n" .
			json_encode($o['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if (!empty($interpolate)) {
			$prompt = Q::interpolate($prompt, $interpolate);
		}

		$response = $this->executeModel($prompt, $inputs, $options);

		$data = json_decode($response, true);
		if (!is_array($data)) {
			throw new Exception("Model did not return valid JSON");
		}

		return $data;
	}


    /**
     * Create an LLM adapter instance from a string or return an existing instance.
     *
     * @method create
     * @static
     * @param {String|Object} adapter Adapter name, FQCN, or instance
     * @param {Object} [options] Optional constructor/options to pass to adapter
     * @return {Object|null} Instance of adapter or null if not found
     */
    public static function create($adapter, $options = array())
    {
        if (empty($adapter)) {
            return null;
        }

        // If already an instance, return it
        if (is_object($adapter)) {
            return $adapter;
        }

        // If full class name provided and exists, instantiate
        if (is_string($adapter) && class_exists($adapter)) {
            return new $adapter($options);
        }

        // Normalize adapter string to a class suffix:
        // e.g. "openai" => "Openai" => "AI_LLM_Openai" ; "open-ai" or "open_ai" => "OpenAi"
        $sanitized = preg_replace('/[^a-z0-9]+/i', ' ', (string)$adapter);
        $suffix = str_replace(' ', '', ucwords($sanitized));

        // Common naming convention: AI_LLM_<Adapter>
        $className = "AI_LLM_{$suffix}";

        if (class_exists($className)) {
            return new $className($options);
        }

        // Try alternative: prefix without underscore (legacy variations)
        $altClass = "AI_LLM_" . $suffix;
        if (class_exists($altClass)) {
            return new $altClass($options);
        }

        // Not found — rely on autoloader to load file by convention if needed,
        // otherwise return null so caller can handle.
        return null;
    }

	/**
	 * Build prompt clauses and JSON schema from observation definitions.
	 *
	 * Observations are local, per-artifact evaluators.
	 *
	 * @method promptFromObservations
	 * @static
	 * @protected
	 * @param {array} $observations
	 *   observationName => {
	 *     promptClause: string,
	 *     fieldNames: array<string>,
	 *     example?: array<string,mixed>
	 *   }
	 * @return {array}
	 *   {
	 *     clauses: array<string>,
	 *     schema: object
	 *   }
	 */
	static function promptFromObservations(array $observations)
	{
		$clauses = array();
		$schema  = array();

		foreach ($observations as $name => $o) {
			if (empty($o['promptClause']) || empty($o['fieldNames'])) {
				continue;
			}

			$clauses[] = "- {$o['promptClause']}";

			if (!isset($schema[$name])) {
				$schema[$name] = array();
			}

			foreach ($o['fieldNames'] as $field) {
				if (isset($o['example']) && array_key_exists($field, $o['example'])) {
					$schema[$name][$field] = $o['example'][$field];
				} else {
					$schema[$name][$field] = null;
				}
			}
		}

		return array(
			'clauses' => $clauses,
			'schema'  => $schema
		);
	}
}
