<?php

/**
 * OpenAI transcription adapter (Whisper).
 *
 * Provides AssemblyAI-style job semantics even though OpenAI
 * transcription is synchronous under the hood.
 *
 * @class AI_Transcription_OpenAI
 * @extends AI_Transcription
 * @implements AI_Transcription_Interface
 */
class AI_Transcription_Openai extends AI_Transcription implements AI_Transcription_Interface
{
	/**
	 * In-memory job store (request lifetime only).
	 * This mirrors job semantics for parity with other providers.
	 *
	 * @property jobs
	 * @type array
	 * @protected
	 */
	protected static $jobs = array();

	/**
	 * Submit a transcription job.
	 *
	 * Supported options:
	 * - language (optional)
	 * - model (default "whisper-1")
	 * - timeout (seconds)
	 * - callback (callable, optional)
	 *
	 * @method transcribe
	 * @param {string} $source Publicly accessible audio/video URL
	 * @param {array} $options Optional parameters
	 * @return {array} Job metadata (id + status)
	 */
	function transcribe($source, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		$jobId = 'openai_' . md5($source . microtime(true));
		$apiKey = Q_Config::expect('AI', 'openAI', 'key');

		// Download audio (OpenAI requires file upload)
		$audio = @file_get_contents($source);
		if ($audio === false) {
			$response = array(
				'id'     => $jobId,
				'status' => 'FAILED',
				'error'  => 'Failed to fetch audio source'
			);

			if ($userCallback && is_callable($userCallback)) {
				call_user_func($userCallback, $response);
			}
			return $response;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'whisper_');
		file_put_contents($tmp, $audio);

		$postFields = array(
			'file'  => new CURLFile($tmp),
			'model' => Q::ifset($options, 'model', 'whisper-1')
		);

		if (!empty($options['language'])) {
			$postFields['language'] = $options['language'];
		}

		$headers = array(
			"Authorization: Bearer $apiKey"
		);

		$result = array(
			'id'     => $jobId,
			'status' => 'PROCESSING'
		);

		$callback = function ($response) use ($jobId, &$result, $userCallback) {

			if ($response === false || $response === null) {
				$result['status'] = 'FAILED';
				$result['error']  = 'OpenAI API unreachable';
			} else {
				$data = json_decode($response, true);
				if (!is_array($data) || empty($data['text'])) {
					$result['status'] = 'FAILED';
					$result['error']  = $data;
				} else {
					$result['status'] = 'COMPLETED';
					$result['text']   = $data['text'];
				}
			}

			self::$jobs[$jobId] = $result;

			if ($userCallback && is_callable($userCallback)) {
				try {
					call_user_func($userCallback, $result);
				} catch (Exception $e) {
					error_log($e);
				}
			}
		};

		Q_Utils::post(
			'https://api.openai.com/v1/audio/transcriptions',
			$postFields,
			null,
			true,
			$headers,
			Q::ifset($options, 'timeout', 300),
			$callback
		);

		@unlink($tmp);

		self::$jobs[$jobId] = $result;
		return $result;
	}

	/**
	 * Fetch a previously submitted transcription.
	 *
	 * @method fetch
	 * @param {string} $transcriptId
	 * @param {array} $options Optional parameters:
	 *   @param {callable} [$options.callback]
	 * @return {array} Transcription result or status
	 */
	function fetch($transcriptId, $options = array())
	{
		$userCallback = Q::ifset($options, 'callback', null);

		if (!isset(self::$jobs[$transcriptId])) {
			$response = array(
				'id'     => $transcriptId,
				'status' => 'NOT_FOUND'
			);
		} else {
			$response = self::$jobs[$transcriptId];
		}

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
	 * Platform name.
	 *
	 * @property platform
	 * @type string
	 */
	public $platform = 'OpenAI';
}