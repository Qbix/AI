<?php

function AI_webhook_transcription_index($params)
{
    $fetched = Q::event("AI/webhook/transcription/fetch/".$params['platform']);
    $lines = '';
    foreach ($fetched['utterances'] as $u) {
        $line = !empty($fetched['speaker_labels'])
            ? "$u[speaker]: $u[text]"
            : $u['text'];
        $start = $u['start'];
        $end = $u['end'];
        $entries[] = compact('line', 'start', 'end');
    }
    $count = count($entries);
    $chunks = array_chunk($entries, ceil($count/10), true);
    $result = '';
    foreach ($chunks as $chunk) {
        $source = '';
        foreach ($chunk as $entry) {
            $source .= $entry['line'] . "\n";
        }
        if (!trim($source)) {
            continue;
        }
        $first = reset($chunk);
        $last = end($chunk);
        $start = $first['start'];
        $end = $last['end'];
        $LLM = new AI_LLM_OpenAI();
        $results = $LLM->summarize($source);
        $s = floor($start / 1000);
        $hms = Q_Utils::secondsToHMS($s);
        $result .= "[$hms]"
            . "\n" . json_encode($results['keywords'])
            . "\n" . $results['summary'] . PHP_EOL . PHP_EOL;
    }
    file_put_contents(APP_FILES_DIR . DS . 'output.txt', $result);
}