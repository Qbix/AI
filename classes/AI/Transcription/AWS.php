<?php

use Aws\TranscribeService\TranscribeServiceClient;

class AI_Transcription_AWS extends AI_Transcription implements AI_Transcription_Interface
{
    protected $client;

    function __construct()
    {
        $this->client = new TranscribeServiceClient([
            'region' => Q_Config::expect('AI', 'aws', 'region'),
            'version' => 'latest',
        ]);
    }

    function transcribe($source, $options = array())
    {
        $jobName = "job_" . md5($source . time());
        $params = [
            'TranscriptionJobName' => $jobName,
            'MediaFormat' => pathinfo($source, PATHINFO_EXTENSION),
            'Media' => ['MediaFileUri' => $source],
            'LanguageCode' => Q::ifset($options, 'language_code', 'en-US')
        ];

        $this->client->startTranscriptionJob($params);
        return ['id' => $jobName];
    }

    function fetch($transcriptId)
    {
        $result = $this->client->getTranscriptionJob([
            'TranscriptionJobName' => $transcriptId
        ]);
        $status = $result['TranscriptionJob']['TranscriptionJobStatus'];
        if ($status !== 'COMPLETED') return ['status' => $status];

        $url = $result['TranscriptionJob']['Transcript']['TranscriptFileUri'];
        $json = file_get_contents($url);
        $data = json_decode($json, true);
        return [
            'status' => 'COMPLETED',
            'text' => $data['results']['transcripts'][0]['transcript'] ?? '',
            'words' => $data['results']['items'] ?? []
        ];
    }

    public $platform = 'AWS Transcribe';
}
