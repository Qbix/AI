<?php

class AI_Transcription_AssemblyAI extends AI_Transcription implements AI_Transcription_Interface
{
    /**
     * Create a transcript from a media file that is accessible via a URL.
     * https://www.assemblyai.com/docs/api-reference/transcripts/get
     * @method transcribe
     * @static
     * @param {string} $source a publicly accessible URL of the audio or video file to transcribe.
     * @param {array} $options any options
     * @param {integer} [$options.audio_end_at] The point in time, in milliseconds, to stop transcribing in your media file. 
     * @param {integer} [$options.audio_start_from] The point in time, in milliseconds, to begin transcribing in your media file. 
     * @param {boolean} [$options.auto_chapters=false] Enable Auto Chapters. 
     * @param {boolean} [$options.auto_highlights=false] Enable Key Phrases. 
     * @param {string} [$options.boost_param="default"] How much to boost specified words. Allowed values: "low", "default", "high". 
     * @param {boolean} [$options.content_safety=false] Enable Content Moderation. 
     * @param {integer} [$options.content_safety_confidence=50] The confidence threshold for Content Moderation model (25-100). 
     * @param {array} [$options.custom_spelling] Customize how words are spelled and formatted using `to` and `from` values. 
     * @param {boolean} [$options.custom_topics=false] Enable custom topics. 
     * @param {boolean} [$options.disfluencies=false] Transcribe filler words like “umm”. 
     * @param {boolean} [$options.dual_channel=false] Enable dual channel transcription. 
     * @param {boolean} [$options.entity_detection=false] Enable entity detection. 
     * @param {boolean} [$options.filter_profanity=false] Filter profanity from the transcribed text. 
     * @param {Boolean} [$options.format_text=false] Enable text formatting. 
     * @param {boolean} [$options.iab_categories=false] Enable topic detection. 
     * @param {boolean} [$options.language_code="en_us"] The language of your audio file. 
     * @param {double} [$options.language_confidence_threshold=0.0] The confidence threshold for automatically detected language. 
     * @param {boolean} [$options.language_detection=false] Enable automatic language detection. 
     * @param {boolean} [$options.punctuate=false] Enable automatic punctuation. 
     * @param {boolean} [$options.redact_pii=false] Redact PII from the transcribed text. 
     * @param {boolean} [$options.redact_pii_audio=false] Generate a copy of the media with PII "beeped". 
     * @param {string} [$options.redact_pii_audio_quality="mp3"] File type of redacted audio (mp3 or wav). 
     * @param {array} [$options.redact_pii_policies] The list of PII Redaction policies to enable. 
     * @param {string} [$options.redact_pii_sub="entity_name"] The replacement logic for detected PII ("entity_name" or "hash"). 
     * @param {boolean} [$options.sentiment_analysis=false] Enable sentiment analysis. 
     * @param {boolean} [$options.speaker_labels=false] Enable speaker diarization. 
     * @param {integer} [$options.speakers_expected] Number of speakers expected (up to 10). 
     * @param {string} [$options.speech_model="best"] The speech model to use ("best" or "nano"). 
     * @param {double} [$options.speech_threshold=0.0] Reject audio files with less than this fraction of speech. 
     * @param {boolean} [$options.summarization=false] Enable summarization. 
     * @param {string} [$options.summary_model="informative"] The summarization model to use ("informative", "conversational", "catchy"). 
     * @param {string} [$options.summary_type="bullets"] The type of summary ("bullets", "gist", "headline", etc.). 
     * @param {array} [$options.topics] The list of custom topics. 
     * @param {string} [$options.webhook_auth_header_name] The header name for webhook auth. 
     * @param {string} [$options.webhook_auth_header_value] The header value for webhook auth. 
     * @param {string} [$options.webhook_url] The URL for transcript webhook notifications. 
     * @param {array} [$options.word_boost] Custom vocabulary for boosting transcription. 
     * @return {array} Returns the status of the transcription as a PHP array.
     *  Possible keys include: "id", "language_model", "acoustic_model",
     *  "language_code", "status", "audio_url", "text", "words".
     *  You will want to call fetch(transcriptionId) sometime later
     */
    function transcribe($source, $options = array())
    {
        $apiKey = Q_Config::expect('AI', 'assemblyAI', 'key');
        $headers = array(
            "Content-Type: application/json",
            "Authorization: $apiKey"
        );
        if ($a = Q::ifset($options, '_diarization', null)) {
            $options['speaker_labels'] = true;
            if (isset($a['max'])) {
                $options['speakers_expected'] = $a['max'];
            }
            unset($options['_diarization']);
        }
        if ($b = Q::ifset($options, '_webhook', null)) {
            $options['webhook_url'] = $b;
            $secretToken = Users::secretToken($this->platform, Q::app());
            $options['webhook_auth_header_name'] = "X-Webhook-Secret";
            $options['webhook_auth_header_value'] = $secretToken;
            unset($options['_webhook']);
        }
        $payload = array('audio_url' => $source) + $options;
        $json = Q_Utils::post(
            'https://api.assemblyai.com/v2/transcript', 
            $payload, null, null, $headers
        );
        return Q::json_decode($json, true);
    }

    /**
     * Fetch an already-generated transcript
     * @method fetch
     * @static
     * @param {string} $transcriptId
     * @return {array} Returns the generated transcript as a PHP array.
     *  Possible keys: "id", "audio_url", "status", "webhook_auth", 
     *  "text", "words", "utterances", 
     *  "auto_highlights", "redact_pii", "summarization", 
     *  "language_model", "acoustic_model", "language_code", 
     *  "language_detection", "language_confidence_threshold", "language_confidence", 
     *  "speech_model", "confidence", 
     *  "audio_duration", "punctuate", "format_text", "disfluencies", 
     *  "dual_channel", "webhook_url", "webhook_status_code", 
     *  "webhook_auth_header_name", "auto_highlights_result", 
     *  "audio_start_from", "audio_end_at", "word_boost", 
     *  "boost_param", "filter_profanity", "redact_pii_audio", 
     *  "redact_pii_audio_quality", "redact_pii_policies", "redact_pii_sub", 
     *  "speaker_labels", "speakers_expected", "content_safety", "content_safety_labels", 
     *  "iab_categories", "iab_categories_result", "custom_spelling",
     *  "auto_chapters", "chapters", 
     *  "summary_type", "summary_model", "summary", 
     *  "custom_topics", "topics", "sentiment_analysis", 
     *  "sentiment_analysis_results", "entity_detection", 
     *  "entities", "speech_threshold", 
     *  "throttled", "error", "speed_boost"
     */
    function fetch($transcriptId)
    {
        $apiKey = Q_Config::expect('AI', 'assemblyAI', 'key');
        $headers = array(
            "Authorization: $apiKey"
        );
        $json = Q_Utils::get(
            "https://api.assemblyai.com/v2/transcript/$transcriptId", 
            null, null, $headers
        );
        return Q::json_decode($json, true);
    }
    
    public $platform = 'AssemblyAI';
}