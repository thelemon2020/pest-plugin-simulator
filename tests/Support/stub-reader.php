<?php

declare(strict_types=1);

/*
 * A stand-in for android/reader.jar on a device: the same protocol over stdin and stdout.
 *
 *   php stub-reader.php <script.json> <requests.log>
 *
 * Says "ready" (unless the script's "ready" is false), then answers each "dump" line with
 * the next of the script's "answers", and any other line ("type ...", "clear", "hide") with
 * the next of its "inputs", "ok" when it has none. The last one repeats. The script carries
 * on across processes, so a reader started again gets the answers after the ones the last
 * one used. An answer is any of:
 *
 *   "<xml>"            a length line and that XML (a dump)
 *   "ok" or "-1"       that line (any other request)
 *   {"none": true}     -1: no window to read
 *   {"hang": true}     nothing, until stdin closes
 *   {"exit": true}     the process ends without answering
 *
 * Each request line is appended to the log, so a test can count and read them.
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
$answers = $script['answers'] ?? [''];
$inputs = $script['inputs'] ?? ['ok'];

while (($line = fgets(STDIN)) !== false) {
    $request = trim($line);
    $dump = $request === 'dump';
    $requests = file($log, FILE_IGNORE_NEW_LINES) ?: [];
    $next = count(array_filter($requests, fn (string $logged): bool => ($logged === 'dump') === $dump));
    file_put_contents($log, $request."\n", FILE_APPEND);
    $scripted = $dump ? $answers : $inputs;
    $answer = $scripted[min($next, count($scripted) - 1)];

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

    fwrite(STDOUT, $dump ? strlen($answer)."\n".$answer : $answer."\n");
    fflush(STDOUT);
}
