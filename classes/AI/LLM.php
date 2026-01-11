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
     *   - some adapters might allow additional &$references to fill besides text
	 *
	 * @return {string} The model returns text requested.
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
     * @param {array} [$options]
	 *   Options to pass to executeModel method
	 * @return {array}
	 *   Observation JSON emitted by the model, or empty array if no observations passed.
	 * @throws {Q_Exception}
	 */
	function process(array $inputs, array $observations, array $interpolate = array(), array $options = array())
	{
		if (empty($observations)) {
			return array();
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
			throw new Q_Exception("Model did not return valid JSON");
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

    /**
     * Can be used to summarize the text, generate keywords for searching, and find out who's speaking.
     * @method summarize
     * @param {string} $text the text to summarize, should fit into the LLM's context window
     * @param {array} [$options=array()] see options of chatCompletions
     * @param {array} [$options.temperature=0] sets 0 temperature for summaries by default
     * @return {array} An array with keys "summary", "keywords" and "speakers"
     */
    function summarize($text, $options = array())
    {
        if (!isset($options['temperature'])) {
            $options['temperature'] = 0;
        }
        if (!isset($options['max_tokens'])) {
            $options['max_tokens'] = 1000;
        }

        if (!trim($text)) {
            return array();
        }

        $instructions = <<<HEREDOC
    You are a language model tasked with extracting structured summaries for indexing, using clearly labeled XML-style tags.

    Output exactly these sections:
    <title> (under 200 characters)
    <keywords> one line, max 400 characters
    <summary> one paragraph, max 512 characters
    <speakers> comma-separated names OR "no names"

    Rules:
    - No extra text
    - No markdown
    - No explanations

    Text to process:
    $text
HEREDOC;

        $response = $this->executeModel(
            $instructions,
            array('text' => $text),
            $options
        );

        $content = is_array($response)
            ? json_encode($response)
            : (string)$response;

        $content = trim(preg_replace('/^```.*?\n|\n```$/s', '', $content));

        preg_match('/<title>(.*?)<\/title>/s', $content, $t);
        preg_match('/<keywords>(.*?)<\/keywords>/s', $content, $k);
        preg_match('/<summary>(.*?)<\/summary>/s', $content, $s);
        preg_match('/<speakers>(.*?)<\/speakers>/s', $content, $sp);

        $title = trim($t[1] ?? '');
        $summary = trim($s[1] ?? '');
        $speakers = trim($sp[1] ?? '');
        $keywordsString = trim($k[1] ?? '');

        $keywords = $keywordsString !== ''
            ? preg_split('/\s*,\s*/', $keywordsString)
            : array();

        if (strtolower($speakers) === 'no names') {
            $speakers = '';
        }

        return compact('title', 'keywords', 'summary', 'speakers');
    }

    /**
     * Expand a list of canonical keywords into related terms for search indexing.
     * 
     * This method sends the given keywords to the language model and requests
     * expansion into related search terms. It is intended to be used both
     * during content insertion and during query processing.
     * 
     * When used during insert time, the model is encouraged to expand the keywords
     * broadly and include synonyms, alternate phrasings, and variations.
     * 
     * When used during query time, the expansion is narrower and more literal,
     * intended to match only closely relevant synonyms or rephrasings that improve recall.
     * 
     * @method keywords
     * @param {array} $keywords An array of 1-word or 2-word canonical keyword strings.
     * @param {string} $during Either 'insert' or 'query' to control expansion depth.
     * @param {array} [$options=array()] Optional OpenAI options like model or temperature.
     * @return {array} An array of expanded keyword terms (strings), deduplicated and lowercased.
     */
    function keywords(array $keywords, $during = 'insert', $options = array())
    {
        if (empty($keywords)) return array();

        $original = implode(', ', $keywords);
        $temperature = ($during === 'query') ? 0.3 : 0.7;

        $prompt = <<<HEREDOC
    Expand the following canonical search keywords into useful query terms.

    Input:
    $original

    Rules:
    - Output a single line
    - Comma-separated
    - Max 1000 terms
    - Each term must be 1 or 2 words
    - No punctuation other than commas
    - No duplicates
    - No sentences
    - Highly relevant only

    Output only the keyword line.
    HEREDOC;

        $response = $this->executeModel(
            $prompt,
            array('text' => $original),
            array_merge(
                array(
                    'temperature' => $temperature,
                    'max_tokens' => 2000
                ),
                $options
            )
        );

        $content = is_array($response)
            ? json_encode($response)
            : (string)$response;

        $content = trim(preg_replace('/^```.*?\n|\n```$/s', '', $content));

        $expanded = preg_split('/\s*,\s*/', $content);
        $expanded = array_unique(
            array_filter(
                array_map('strtolower', $expanded)
            )
        );

        return array_values($expanded);
    }


    /**
	 * Use this function to merge all the files under AI/observations config,
	 * and get info for all potential observations, indexed by their name.
	 *
	 * After loading all configs, each top-level entry is interpolated using
	 * Q::interpolate($value, $rootArray).
	 *
	 * @method userStreamsTree
	 * @static
	 */
	static function observationsTree()
	{
		static $p = null;
		static $previousArr = null;

		$arr = Q_Config::get('AI', 'observations', array());
		if ($p && $previousArr === $arr) {
			return $p;
		}
		$previousArr = $arr;

		$p = new Q_Tree();
		$app = Q::app();

		foreach ($arr as $k => $v) {
			$PREFIX = ($k === $app ? 'APP' : strtoupper($k).'_PLUGIN');
			$path = constant($PREFIX . '_CONFIG_DIR');
			$p->load($path . DS . $v);
		}

		// Interpolate each top-level entry
		$communityId = Users::communityId();
		$currentCommunityId = Users::currentCommunityId();
        $currentYear = date("Y");
		$loggedInUser = Users::loggedInUser();
		$loggedInUserId = $loggedInUser ? $loggedInUser->id : null;
		$vars = compact(
			'communityId', 'currentCommunityId', 'loggedInUserId', 'currentYear'
		);
		$all = $p->getAll();
		$interp = array();
		foreach ($all as $key => $value) {
			$newKey = Q::interpolate($key, $vars);
			if ($newKey !== $key) {
				$p->clear($key);
				$interp[$newKey] = $value;
			}
		}
		foreach ($interp as $k => $v) {
			$p->set($k, $v);
		}
		return $p;
    }

    /**
     * Helper method to load all observation definitions from config,
     * for a given type of input.
     * @method observations
     * @static
     * @param {string} $streamType
     * @param {string} $observationsType for that stream type
     * @return {array}
     */
    static function observations($streamType, $observationsType)
    {
        return self::observationsTree()->get($streamType, $observationsType, array());
    }

    /**
     * Gather deterministic stream attributes from data
     * constrained strictly by observation fieldNames.
     *
     * @method fieldNames
     * @static
     * @param {string} [$streamType] e.g. "Streams/images"
     * @param {string} [$observationsType] e.g. "holiday" for that stream type
     * @param {array} [$results] data with the results to gather from
     * @return {array}
     */
    static function fieldNames($streamType, $observationsType, array $results)
    {
        $attr = array();

        // Load observation definitions
        $observations = self::observations($streamType, $observationsType);
        if (empty($observations)) {
            return $attr;
        }

        // Collect allowed fieldNames
        $allowed = array();
        foreach ($observations as $obs) {
            if (empty($obs['fieldNames']) || !is_array($obs['fieldNames'])) {
                continue;
            }
            foreach ($obs['fieldNames'] as $field) {
                $allowed[$field] = true;
            }
        }

        // Filter + normalize
        foreach ($allowed as $field => $stuff) {

            if (!array_key_exists($field, $results)) {
                continue;
            }

            $value = $results[$field];

            // Strings: trim + drop empty
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') continue;
            }

            // Arrays: compact + reindex
            if (is_array($value)) {
                $value = array_values(array_filter($value, function ($v) {
                    return $v !== null && $v !== '';
                }));
                if (empty($value)) continue;
            }

            // Scalars: pass through
            $attr[$field] = $value;
        }

        return $attr;
    }

    /**
     * Run observations and optionally create a stream.
     *
     * @method createStream
     * @param {string} $streamType 
     * @param {string} Observation type (e.g. "images/holiday")
     * @param {array} $stream Stream creation data (publisherId, type, title?, icon?)
     * @param {array} [$results] Deterministic results of a model call
     * @param {array} [$options] Behavior overrides
     * @param {string} [$options.adapter] LLM adapter name
     * @param {callable|true} [$options.accept] Acceptance callback, set to true to always accept
     * @return {Streams_Stream|false} Created stream or false
     */
	static function createStream(
		$streamType,
        $observationsType,
		array $stream,
		array $results = array(),
		array $options = array()
	) {
		// 1) Deterministic attributes
		$attributes = self::fieldNames($streamType, $observationsType, $results);

        $accept = Q::ifset($options, 'accept', array('AI_LLM', 'accept'));
        if ($accept && $accept !== true && !call_user_func($accept, $attributes)) {
            return false;
        }

		// 3) Create stream
		$title = Q::ifset($stream, 'title', null);
		if ($title === null && isset($attributes['title'])) {
			$title = $attributes['title'];
			unset($attributes['title']);
		}

        $publisherId = Q::ifset($options, 'publisherId', Q::app());
		return Streams::create(
			'AI',
			$publisherId,
			$streamType,
			array(
				'title' => $title,
				'icon' => Q::ifset($stream, 'icon', null),
				'attributes' => $attributes
			),
			array(
				'skipAccess' => true
			)
		);
	}


	/**
	 * Hard policy gate. Rejects before attributes are even written.
	 * @method accept
	 * @static
	 * @param {array} $attributes
	 * @return {boolean} 
	 */
	static function accept($attributes)
	{
		if (Q::ifset($attributes, 'obscene', 10) > 3
		 || Q::ifset($attributes, 'controversial', 10) > 5
		 || Q::ifset($attributes, 'confidence', 0) < 0.6) {
			return false;
		}

		return true;
	}

	/**
	 * Extract a compact, attribute-safe, FLAT attribute set
	 * from LLM observation results.
	 *
	 * Output shape matches Streams_Stream::syncRelations expectations.
	 *
	 * @method attributesFromObservationResults
	 * @static
	 * @param {array} $observations Full LLM output (keyed by observation section)
     * @param {string} [$streamType="Streams/image"]
	 * @param {string} [$observationsType="holiday"]
	 * @return {array} flat array of attributes
	 */
	static function attributesFromObservationResults(array $observations, $streamType = 'Streams/image', $observationsType = 'holiday')
	{
		$out = array();

		// Load observation definitions
		$defs = AI_LLM::observations($streamType, $observationsType);
		if (empty($defs)) {
			return $out;
		}

		// Collect allowed fieldNames
		$allowed = array();
		foreach ($defs as $def) {
			if (empty($def['fieldNames']) || !is_array($def['fieldNames'])) {
				continue;
			}
			foreach ($def['fieldNames'] as $field) {
				$allowed[$field] = true;
			}
		}

		// Flatten observations
		foreach ($observations as $section => $values) {

			if (!is_array($values)) {
				continue;
			}

			foreach ($values as $field => $value) {

				// Strict allowlist
				if (empty($allowed[$field])) {
					continue;
				}

				// Normalize strings
				if (is_string($value)) {
					$value = trim($value);
					if ($value === '') continue;

					// Truncate short semantic text
					if ($field === 'title' || $field === 'subtitle') {
						$value = mb_substr($value, 0, 100);
					}
				}

				// Normalize arrays
				if (is_array($value)) {
					$value = array_values(array_filter($value, function ($v) {
						return $v !== null && $v !== '';
					}));
					if (!$value) continue;

					// Hard bounds for keyword arrays
					if ($field === 'keywords' || $field === 'keywordsNative') {
						$value = array_slice($value, 0, 10);
						$value = array_map(function ($v) {
							$v = Q_Utils::normalize($v);
							return mb_substr((string)$v, 0, 32);
						}, $value);
						$value = array_values(array_filter($value, 'strlen'));
						if (!$value) continue;
					}
				}

				// Scalars: pass through
				if ($value === null) {
					continue;
				}

				$out[$field] = $value;
			}
		}

		return $out;
	}

}
