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
Under "keywords" you have an array containing 50 strings that are 1-word keywords or 2-word key phrases, that would help someone find the text to be summarized
in the archives. Please list only the most common 1-word keywords or 2-word key phrases
that people are most likely to search for on the internet, when specifically looking for the content being summarized,
you can use synonyms that are extremely common. The keywords must be in the same language as the source content.
HEREDOC;
        $summaryInstructions = <<<HEREDOC
Under "summary" is a string less than 512 characters that accurately summarizes the content, in one single cohesive and easy-to-follow paragraph.
Avoid run-on sentences and multiple paragraphs, just make the summary accurate and succinct.
When summarizing, please ignore any sentences that seem like they're part of an advertisement inserted inside the transcript.
Do not refer to the speakers A, B, etc. directly, or the hosts.
You should not refer to "the content", "the text", "the discussion", "the conversation" or anything meta like that.
Just summarize the substance of what is being said by the speakers,
as if it was a shorter version being said by one speaker. The summary should be comprehensive and fit in under 512 characters.
HEREDOC;

        $speakersInstructions = <<<HEREDOC
Under "spakers" is an array contining the names of the speakers, if they can be clearly deduced from the text,
otherwise it is an empty JSON array.
HEREDOC;

        $instructions = <<<HEREDOC
I need you to summarize some text and output a JSON structure.
I am including the text to be summarized after these instructions.
Follow the instructions exactly. Do not include any bold headers. 
No preambles, please output only JSON of a Javascript object with the keys "keywords", "summary", "speakers".

$keywordsInstructions

$summaryInstructions

$speakersInstructions

What follows is what you need to summarize:

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
        return json_decode($content, true);
    }
}