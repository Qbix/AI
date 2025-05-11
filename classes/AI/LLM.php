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
Inside the keywords section, please output 50 comma-separated entries consisting of 1-word keywords or 2-word key phrases,
that would help someone find the text to be summarized in the archives. These will be used for indexing!
List only the most common 1-word keywords or 2-word key phrases
that people are most likely to search for on the internet, when specifically looking for the content being summarized,
you can use synonyms that are extremely common. The keywords must be in the same language as the source content.
HEREDOC;
        $summaryInstructions = <<<HEREDOC
Inside the summary section, and output a string less than 512 characters that accurately summarizes the content, in one single cohesive and easy-to-follow paragraph.
In this string, avoid run-on sentences and multiple paragraphs, just make the summary accurate and succinct.
When summarizing, please ignore any sentences that seem like they're part of an advertisement inserted inside the transcript.
Do not refer to the speakers A, B, etc. directly, or the hosts.
You should not refer to "the content", "the text", "the discussion", "the conversation" or anything meta like that.
Just summarize the substance of what is being said by the speakers,
as if it was a shorter version being said by one speaker. The summary should be comprehensive and fit in under 512 characters.
HEREDOC;

        $speakersInstructions = <<<HEREDOC
Inside speakers section, put the exact string "no names" if you can't figure out who is speaking from the text, otherwise put a comma-separated list of speaker names deduced from the text.
HEREDOC;

        $instructions = <<<HEREDOC
You are a large language model that can summarize text, generate keywords for searching, and find out who's speaking.
Output exactly three sections, each section consisting of exactly one line,
and marked exactly like this example found between the === lines:

===
<keywords>
keyword1, keyword2, ...
</keywords>

<summary>
your paragraph summary under 512 characters.
</summary>

<speakers>
Mark, Stephanie
</speakers>
===

I am including the text to be summarized after these instructions.
Follow the instructions exactly. Do not include any bold headers. 

$keywordsInstructions

$summaryInstructions

$speakersInstructions

What follows is the text you need to summarize according to the instructions above:

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
        $parts = explode("\n\n", $content);
        $keywordsString = Q::ifset($parts, 0, '');
        $summary = Q::ifset($parts, 1, '');
        $speakers = Q::ifset($parts, 2, '');

        $keywords = preg_split('/\s*,\s*/', trim($keywordsString));
        if (trim($speakers) === 'no names') {
            $speakers = '';
        }
        $keywords = preg_split('/\s*,\s*/', trim($keywordsString));
        if ($speakers === 'no names') {
            $speakers = '';
        }
        return compact('keywords', 'summary', 'speakers');
    }
}