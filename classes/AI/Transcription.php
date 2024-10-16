<?php

interface AI_Transcription_Interface
{
    function transcribe($source, $options = array());

    function fetch($transcriptId);
}

class AI_Transcription implements AI_Transcription_Interface
{
    /**
     * @method transcribe
     * @static
     * @param {string} $source a publicly accessible URL of the audio or video file to transcribe.
     * @param {array} $options any options for the specific service,
     *   or the following standard options:
     * @param {array} [$options._diarization=] Enable speaker diarization. 
     * @param {array} [$options._diarization.max=] Maximum number of speakers expected (up to 10). 
     */
    function transcribe($source, $options = array())
    {
        // by default, return an empty array
        return array();
    }

    /**
     * Fetch an already-generated transcript
     * @method fetch
     * @static
     * @param {string} $transcriptId
     * @return {array} Returns the generated transcript as a PHP array.
     */
    function fetch($transcriptId)
    {
        // by default, return an empty array
        return array();
    }

    public $platform = null;
}