<?php

class AI_Transcription_OpenAI extends AI_Transcription implements AI_Transcription_Interface
{
	/**
	 * Create a transcript from a local media file using OpenAI Whisper API.
	 *
	 * @method transcribe
	 * @static
	 * @param {string} $source Path or URL to an audio file (e.g., .mp3, .wav)
	 * @param {array} [$options] Optional parameters:
	 *   @param {string} [$options.language] Language code (e.g., 'en')
	 *   @param {string} [$options.prompt] Optional prompt to guide transcription
	 *   @param {float} [$options.temperature=0.0] Sampling temperature
	 *   @param {string} [$options.response_format="json"] Format of the response: "text", "json", or "verbose_json"
	 *   @param {array} [$options.timestamp_granularities] Required for "verbose_json". Example: ["word"]
	 *   @param {array} [$options.chunks] Optional chunking parameters
	 *     @param {int} [$options.chunks.duration] Override chunk duration from Q_Config
	 * @return {array|string} Transcription result. Array for JSON/verbose_json, string for plain text
	 * @throws {Exception} If the request fails
	 */
	function transcribe($source, $options = array())
	{
		$chunkDuration = isset($options['chunks']['duration'])
			? $options['chunks']['duration']
			: Q_Config::get('AI', 'audio', 'chunks', 'duration', 0);

		if (filter_var($source, FILTER_VALIDATE_URL)) {
			$tempFile = tempnam(sys_get_temp_dir(), 'openai_audio_');
			copy($source, $tempFile);
			$source = $tempFile;
			$deleteAfter = true;
		} else {
			$deleteAfter = false;
		}

		if ($chunkDuration > 0) {
			$chunkPattern = sys_get_temp_dir() . '/chunk_' . uniqid() . '_%03d.mp3';
			shell_exec("ffmpeg -i " . escapeshellarg($source) .
				" -f segment -segment_time $chunkDuration -c copy " . escapeshellarg($chunkPattern));
			$chunkFiles = glob(dirname($chunkPattern) . '/' . basename($chunkPattern, '%03d.mp3') . '*.mp3');
			sort($chunkFiles);

			$apiKey = Q_Config::expect('OpenAI', 'key');
			$mh = curl_multi_init();
			$curlHandles = [];
			$results = [];

			foreach ($chunkFiles as $i => $chunk) {
				$postFields = array(
					'file' => new CURLFile($chunk),
					'model' => 'whisper-1',
				);
				if (isset($options['language'])) $postFields['language'] = $options['language'];
				if (isset($options['prompt'])) $postFields['prompt'] = $options['prompt'];
				if (isset($options['temperature'])) $postFields['temperature'] = $options['temperature'];
				if (isset($options['response_format'])) $postFields['response_format'] = $options['response_format'];
				else $postFields['response_format'] = 'json';
				if (isset($options['timestamp_granularities'])) $postFields['timestamp_granularities'] = $options['timestamp_granularities'];

				$ch = curl_init();
				curl_setopt_array($ch, array(
					CURLOPT_URL => 'https://api.openai.com/v1/audio/transcriptions',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $postFields,
					CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiKey"]
				));
				$curlHandles[$i] = $ch;
				curl_multi_add_handle($mh, $ch);
			}

			do {
				$status = curl_multi_exec($mh, $active);
				curl_multi_select($mh);
			} while ($active && $status == CURLM_OK);

			foreach ($curlHandles as $i => $ch) {
				$res = curl_multi_getcontent($ch);
				$decoded = Q::json_decode($res, true);
				$results[$i] = isset($decoded['text']) ? $decoded['text'] :  '';
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);

			$merged = '';
			$last = '';
			foreach ($results as $text) {
				$clean = trim($text);
				if (!$clean) continue;
				// ASCII normalization and case-insensitive overlap comparison
				$a = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $last));
				$b = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $clean));
				$maxOverlap = min(100, min(strlen($a), strlen($b)));
				$overlap = 0;
				for ($j = $maxOverlap; $j > 10; $j--) {
					if (substr($a, -$j) === substr($b, 0, $j)) {
						$overlap = $j;
						break;
					}
				}
				$merged .= ($overlap ? substr($clean, $overlap) : "\n" . $clean);
				$last = $clean;
			}

			foreach ($chunkFiles as $f) unlink($f);
			if ($deleteAfter && file_exists($source)) unlink($source);
			return trim($merged);
		}

		$apiKey = Q_Config::expect('OpenAI', 'key');
		$headers = array("Authorization: Bearer $apiKey");
		$postFields = array('file' => new CURLFile($source), 'model' => 'whisper-1');
		if (isset($options['language'])) $postFields['language'] = $options['language'];
		if (isset($options['prompt'])) $postFields['prompt'] = $options['prompt'];
		if (isset($options['temperature'])) $postFields['temperature'] = $options['temperature'];
		if (isset($options['response_format'])) $postFields['response_format'] = $options['response_format'];
		else $postFields['response_format'] = 'json';
		if (isset($options['timestamp_granularities'])) $postFields['timestamp_granularities'] = $options['timestamp_granularities'];

		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => 'https://api.openai.com/v1/audio/transcriptions',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $postFields,
			CURLOPT_HTTPHEADER => $headers
		));

		$response = curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);
		if ($deleteAfter && file_exists($source)) unlink($source);
		if ($error) throw new Exception("OpenAI Whisper API error: $error");
		if ($postFields['response_format'] === 'text') return $response;
		return Q::json_decode($response, true);
	}

	/**
	 * Fetch is not applicable for OpenAI Whisper API.
	 * Transcription is synchronous and immediate.
	 *
	 * @method fetch
	 * @static
	 * @param {string} $transcriptId Ignored for OpenAI
	 * @return {null} Always returns null
	 */
	function fetch($transcriptId)
	{
		return null;
	}

	public $platform = 'OpenAI';
}