<?php

declare(strict_types=1);

/*
 * A stand-in for android/reader.jar on a device: the same protocol over stdin and stdout.
 *
 *   php stub-reader.php <script.json> <requests.log>
 *
 * Says "ready" (unless the script's "ready" is false), then answers each "dump" line with
 * the next scripted answer, the last one repeating. The script carries on across processes,
 * so a reader started again gets the answers after the ones the last one used. An answer
 * is any of:
 *
 *   "<xml>"            a length line and that XML
 *   {"none": true}     -1: no window to read
 *   {"hang": true}     nothing, until stdin closes
 *   {"exit": true}     the process ends without answering
 *
 * Each request is appended to the log, so a test can count them.
 */

$script = json_decode((string) file_get_contents($argv[1]), true);
$log = $argv[2];

// Hanging means reading on until stdin closes, so closing the stub still ends it.
if (($script['ready'] ?? true) === false) {
    stream_get_contents(STDIN);
    exit(0);
}

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$answers = $script['answers'];

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) !== 'dump') {
        continue;
    }

    $next = count(file($log) ?: []);
    file_put_contents($log, "dump\n", FILE_APPEND);
    $answer = $answers[min($next, count($answers) - 1)];

    if (is_array($answer) && ($answer['hang'] ?? false)) {
        stream_get_contents(STDIN);
        exit(0);
    }

    if (is_array($answer) && ($answer['exit'] ?? false)) {
        exit(0);
    }

    if (is_array($answer) && ($answer['none'] ?? false)) {
        fwrite(STDOUT, "-1\n");
        fflush(STDOUT);

        continue;
    }

    fwrite(STDOUT, strlen($answer)."\n".$answer);
    fflush(STDOUT);
}
