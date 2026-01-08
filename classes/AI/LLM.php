<?php

interface AI_LLM_Interface
{
    /**
     * @method chatCompletions
     * @param {array} messages An array of role => content, where role can be "system", "user", "assistant"
     * @param {array} $options
     * @param {string} [$model="gpt-3.5-turbo"] You can override which chat model to use
     * @param {integer} [$max_tokens=3000] Maximum number of tokens to return
     * @param {integer} [$temperature=0.5] How many results to return
     * @param {integer} [$numResults=1] How many results to return
     * @param {integer} [$presencePenalty=2]
     * @param {integer} [$frequencyPenalty=2]
     * @return {array} Contains "errors" or "choices" keys
     */
    function chatCompletions(array $messages, $options = array());
}

class AI_LLM implements AI_LLM_Interface
{
    /**
     * Execute a chat-style completion against the underlying model provider.
     *
     * This method is intended to be implemented by concrete adapters
     * (e.g. OpenAI, Anthropic, local models, etc.).
     *
     * The base implementation returns an empty array and should be
     * overridden by subclasses.
     *
     * Implementations SHOULD:
     *  - serialize messages according to the provider API
     *  - perform authentication and transport
     *  - return the decoded response as an associative array
     *
     * They MUST NOT:
     *  - interpret semantic meaning
     *  - extract text or JSON payloads
     *  - apply policies or accumulation
     *
     * @method chatCompletions
     * @param {array} $messages
     *   Normalized message structure, typically:
     *   {
     *     system: string,
     *     user: array<array>  // multimodal content blocks
     *   }
     * @param {array} [$options]
     *   Optional provider-specific options, such as:
     *   - model
     *   - temperature
     *   - response_format
     *   - timeout
     * @return {array}
     *   Decoded model response structure.
     */
    function chatCompletions(array $messages, $options = array())
    {
        // Base implementation: no-op
        return array();
    }

    /**
     * Invoke the underlying model using a normalized prompt and inputs.
     *
     * This method performs provider-agnostic orchestration:
     *  - builds normalized messages
     *  - delegates execution to chatCompletions()
     *
     * The returned value MUST be a decoded response array, which will
     * later be interpreted by extractModelPayload().
     *
     * @method callModel
     * @protected
     * @param {string} $prompt
     *   Fully constructed system prompt.
     * @param {array} $inputs
     *   Multimodal inputs, such as:
     *   {
     *     text: string,
     *     images: array<binary>
     *   }
     * @return {string}
     *   Decoded model response.
     * @throws {Exception}
     */
    protected function callModel($prompt, array $inputs)
    {
        $messages = $this->buildMessages($prompt, $inputs);

        $response = $this->chatCompletions($messages, array(
            'response_format' => 'json'
        ));

        if (!is_array($response)) {
            throw new Exception("Model did not return a structured response");
        }

        return $this->extractModelPayload($response);
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
        $keywordsInstructions = <<<HEREDOC
Inside the <keywords> section, output a **single line** with up to 50 comma-separated 1-word keywords or 2-word key phrases that would help someone find the text in an archive or search engine.

Only include the most relevant and commonly searched terms, using synonyms or generalizations if needed. Prioritize relevance and common usage.

The entire <keywords> section must not exceed 400 characters (including commas). Do not use newlines, bullet points, or any other formatting.
HEREDOC;
        
        $summaryInstructions = <<<HEREDOC
Inside the <summary> section, write a single paragraph (less than 512 characters) summarizing the **core ideas** expressed in the text.

Avoid run-on sentences and do not use multiple paragraphs. Ignore any promotional or advertising content.

Do not refer to "this conversation", "the content", or the names of hosts or speakers directly. Just express what was said, clearly and neutrally, as if paraphrasing it into a shorter version.
HEREDOC;
        
        $speakersInstructions = <<<HEREDOC
Inside the <speakers> section, write either:
- A comma-separated list of speaker names (if clearly identifiable in the text)
- Or the exact string: no names

Do not guess. If the speakers are not explicitly named, respond with "no names" exactly.
HEREDOC;
        
        $instructions = <<<HEREDOC
You are a language model tasked with extracting structured summaries for indexing, using clearly labeled XML-style tags.

Output **exactly three sections**:
1. <keywords> one line, 400 characters max
2. <summary> one paragraph, 512 characters max
3. <speakers> either names or "no names"

Follow this format exactly, without variation. Example:

===
<title>
Generate a title for the text, comfortably under 200 characters
</title>

<keywords>
keyword1, keyword2, keyword3, ...
</keywords>

<summary>
This is the 1-paragraph summary of the main points from the text.
</summary>

<speakers>
name1, name2
</speakers>
===

Now process the following text:

$text
HEREDOC;

        if (!trim($text)) {
            return array();
        }
        $user = $instructions . $text;
        $messages = array(
            'system' => 'You are generating a concise summary and determining the best keywords',
            'user' => $user
        );
        $completions = $this->chatCompletions($messages, $options);
        $content = trim(Q::ifset(
            $completions, 'choices', 0, 'message', 'content', ''
        ));
        
        // Optionally strip code fences if hallucinated
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        
        // Extract content between tags
        preg_match('/<title>(.*?)<\/title>/s', $content, $t);
        preg_match('/<keywords>(.*?)<\/keywords>/s', $content, $k);
        preg_match('/<summary>(.*?)<\/summary>/s', $content, $s);
        preg_match('/<speakers>(.*?)<\/speakers>/s', $content, $sp);
        
        $title = trim(isset($t[1]) ? $t[1] : '');
        $summary = trim(isset($s[1]) ? $s[1] : '');
        $speakers = trim(isset($sp[1]) ? $sp[1] : '');
        $keywordsString = trim(isset($k[1]) ? $k[1] : '');
        $keywords = preg_split('/\s*,\s*/', $keywordsString);
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
        if (empty($keywords)) return [];

        $original = implode(', ', $keywords);
        $modeDescription = $during === 'query'
            ? "closely related synonyms and rephrasings"
            : "a wide variety of synonyms, variations, alternate phrasing, related terms, abbreviations, and common search terms";

        $prompt = <<<HEREDOC
You are expanding canonical search keywords into useful query terms for a search engine.

Here is the input:
$original

Please output up to 1000 comma-separated search terms that people might use when looking for this content.

Strict rules:
- Only output one line.
- Each term must be 1 or 2 words maximum. No more than 2 words.
- No special characters, punctuation, or formatting.
- No duplicates.
- Do NOT include full sentences.
- All terms must be highly relevant, not generic.
- Think of synonyms, rephrasings, subtopics, and variations.
- Imagine a smart autocomplete or tag system for searching.

Example output:
free talk, libertarian radio, bitcoin price, parking gender, bartering app, cryptocurrency tax, feminist activism

Now output the expanded keyword line:
HEREDOC;

        $messages = [
            'system' => 'You expand keywords for content indexing.',
            'user' => $prompt
        ];

        $options = array_merge([
            'model' => 'gpt-4o',
            'temperature' => $during === 'query' ? 0.3 : 0.7,
            'max_tokens' => 2000,
        ], $options);

        $completions = $this->chatCompletions($messages, $options);
        $content = trim(Q::ifset($completions, 'choices', 0, 'message', 'content', ''));

        // Remove any markdown code fences if hallucinated
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);

        // Parse and sanitize the final list
        $expanded = preg_split('/\s*,\s*/', $content);
        $expanded = array_unique(array_filter(array_map('strtolower', $expanded)));

        return $expanded;
    }

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
	public function process(array $inputs, array $observations, array $interpolate = array())
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

		$response = $this->callModel($prompt, $inputs);

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

    /**
     * Extract semantic string payload from a model response.
     *
     * Supports text-based responses and base64-encoded JSON payloads.
     *
     * @method extractModelPayload
     * @protected
     * @param {array} $response
     * @return {string}
     * @throws {Exception}
     */
    protected function extractModelPayload(array $response)
    {
        // Case 1: base64 JSON payload (highest priority)
        if (!empty($response['data'][0]['b64_json'])) {
            $decoded = base64_decode($response['data'][0]['b64_json'], true);
            if ($decoded === false || $decoded === '') {
                throw new Exception("Failed to decode b64_json payload");
            }
            return $decoded;
        }

        // Case 2: chat completion text
        if (!empty($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }

        throw new Exception("No usable payload found in model response");
    }

    protected function buildMessages($prompt, array $inputs)
    {
        $messages = array(
            'system' => $prompt
        );

        $userContent = array();

        if (!empty($inputs['text'])) {
            $userContent[] = array(
                'type' => 'text',
                'text' => $inputs['text']
            );
        }

        if (!empty($inputs['images'])) {
            foreach ($inputs['images'] as $image) {
                $userContent[] = array(
                    'type' => 'image_url',
                    'image_url' => array(
                        'url' => 'data:image/png;base64,' . base64_encode($image)
                    )
                );
            }
        }

        $messages['user'] = $userContent;
        return $messages;
    }


}