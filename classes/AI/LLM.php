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
     * @param {integer} [$temperature=0.9] How many results to return
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
     * @return {array} An array with keys "summary", "keywords" and "speakers"
     */
    function summarize($text, $options = array())
    {
        $keywordsInstructions = <<<HEREDOC
First, please output, on a line by itself without a header, a JSON array of the top 20
keywords or extremely short key phrases, that would help someone find the text to be summarized
in the archives. At the end of this list, on the same line, continue to add
30-40 more comma-separated, short common keywords that people are most likely to search for
on the internet, when specifically looking for this content, such as synonyms.
Do not include bold headers. The keywords must be in the same language as the source content.
HEREDOC;
        $summaryInstructions = <<<HEREDOC
After the JSON, make a newline, and then accurately summarize the content, in one single cohesive and easy-to-follow paragraph.
Avoid run-on sentences and multiple paragraphs, just make the summary accurate and succinct.
When summarizing, please ignore any sentences that seem like they're part of an advertisement inserted inside the transcript.
Do not refer to the speakers A, B, etc. directly, or the hosts.
You should not refer to "the content", "the text", "the discussion", "the conversation" or anything meta like that.
Just summarize the substance of what is being said by the speakers,
as if it was a shorter version being said by one speaker.
HEREDOC;

        $speakersInstructions = <<<HEREDOC
Finally, if the text clearly says who the speakers are, please list their names as a JSON array.
Otherwise, output an empty JSON array like [].
HEREDOC;

        $instructions = <<<HEREDOC
I am including some text after these instructions.
Follow the instructions exactly.
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
        list($keywordsString, $summary, $speakers) = explode("\n\n", $content);
        $keywords = preg_split ('/(\s*,*\s*)*,+(\s*,*\s*)*/', str_replace(array(';', '.'), ',', $keywordsString));
        if ($speakers === 'no names') {
            $speakers = '';
        }
        return compact('keywords', 'summary', 'speakers');
    }
}