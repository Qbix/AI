<?php

interface AI_LLM_Interface
{
    function chatCompletions(array $messages, $options = array());
}

class AI_LLM implements AI_LLM_Interface
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
    function chatCompletions(array $messages, $options = array())
    {
        // by default, return an empty array
        return array();
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
<keywords>
keyword1, keyword2, keyword3, ...
</keywords>

<summary>
This is the 1-paragraph summary of the main points from the text.
</summary>

<speakers>
Mark, Stephanie
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
        preg_match('/<keywords>(.*?)<\/keywords>/s', $content, $k);
        preg_match('/<summary>(.*?)<\/summary>/s', $content, $s);
        preg_match('/<speakers>(.*?)<\/speakers>/s', $content, $sp);
        
        $keywordsString = trim($k[1] ?? '');
        $summary = trim($s[1] ?? '');
        $speakers = trim($sp[1] ?? '');
        
        $keywords = preg_split('/\s*,\s*/', $keywordsString);
        if (strtolower($speakers) === 'no names') {
            $speakers = '';
        }
        
        return compact('keywords', 'summary', 'speakers');        
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

}