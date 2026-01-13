<?php

use Aws\TranscribeService\TranscribeServiceClient;

/**
 * AWS Transcribe adapter.
 *
 * Mirrors AssemblyAI's two-phase async model:
 * 1) transcribe() submits a job and returns an ID
 * 2) fetch() polls for completion and returns results
 *
 * @class AI_Transcription_AWS
 * @extends AI_Transcription
 * @implements AI_Transcription_Interface
 */
class AI_Transcription_AWS extends AI_Transcription implements AI_Transcription_Interface
{
	/**
	 * @property client
	 * @type TranscribeServiceClient
	 * @protected
	 */
	protected $client;

	/**
	 * Constructor.
	 *
	 * @method __construct
	 */
	function __construct()
	{
		$this->client = new TranscribeServiceClient(array(
			'region'  => Q_Config::expect('AI', 'aws', 'region'),
			'version' => 'latest',
		));
	}

	/**
	 * Submit a transcription job.
	 *
	 * This method is asynchronous: it returns a job ID immediately.
	 * Call fetch($id) later to retrieve results.
	 *
	 * Supported options:
	 * - language_code (default "en-US")
	 * - callback (callable, optional) — called with submission response
	 *
	 * @method transcribe
	 * @param {string} $source Publicly accessible media URL
	 * @param {array} $options Optional parameters
	 * @return {array} Job metadata (at least ['id' => string])
	 */
	function transcribe($source, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		$jobName = 'job_' . md5($source . microtime(true));

		$params = array(
			'TranscriptionJobName' => $jobName,
			'MediaFormat'          => pathinfo($source, PATHINFO_EXTENSION),
			'Media'                => array(
				'MediaFileUri' => $source
			),
			'LanguageCode'         => Q::ifset($options, 'language_code', 'en-US')
		);

		try {
			$this->client->startTranscriptionJob($params);

			$response = array(
				'id'     => $jobName,
				'status' => 'SUBMITTED',
				'platform' => $this->platform
			);
		} catch (Exception $e) {
			$response = array(
				'error' => $e->getMessage()
			);
		}

		// Optional submission callback (parity with AssemblyAI)
		if ($userCallback && is_callable($userCallback)) {
			try {
				call_user_func($userCallback, $response);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		return $response;
	}

	/**
	 * Fetch the status or result of a transcription job.
	 *
	 * @method fetch
	 * @param {string} $transcriptId
	 * @param {array} $options Optional parameters:
	 *   @param {callable} [$options.callback] Optional callback
	 * @return {array} Transcription status or completed transcript
	 */
	function fetch($transcriptId, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		try {
			$result = $this->client->getTranscriptionJob(array(
				'TranscriptionJobName' => $transcriptId
			));
		} catch (Exception $e) {
			$response = array(
				'error' => $e->getMessage()
			);

			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $response);
			}
			return $response;
		}

		$job = $result['TranscriptionJob'];
		$status = $job['TranscriptionJobStatus'];

		// Not finished yet
		if ($status !== 'COMPLETED') {
			$response = array(
				'id'     => $transcriptId,
				'status' => $status
			);

			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $response);
			}

			return $response;
		}

		// Completed: fetch transcript JSON
		$url  = $job['Transcript']['TranscriptFileUri'];
		$json = @file_get_contents($url);
		if ($json === false) {
			$response = array(
				'status' => 'COMPLETED',
				'error'  => 'Failed to fetch transcript file'
			);

			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $response);
			}

			return $response;
		}

		$data = json_decode($json, true);

		$response = array(
			'id'     => $transcriptId,
			'status' => 'COMPLETED',
			'text'   => isset($data['results']['transcripts'][0]['transcript'])
				? $data['results']['transcripts'][0]['transcript']
				: '',
			'words'  => isset($data['results']['items'])
				? $data['results']['items']
				: array()
		);

		if ($userCallback && is_callable($userCallback)) {
			call_user_func($userCallback, $response);
		}

		return $response;
	}

	/**
	 * Platform name.
	 *
	 * @property platform
	 * @type string
	 */
	public $platform = 'AWS Transcribe';
}
