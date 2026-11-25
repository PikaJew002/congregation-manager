<?php

/*
 * HTTP front for the container. The Durable Object POSTs one job to /run and
 * keeps the request open until the job exits. The job runs as a child process
 * so this script can write a space every few seconds; that keeps bytes moving
 * on a connection that can stay open for an hour or more during an encode.
 * The last line of the response body is the JSON result.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/run') {
    header('Content-Type: text/plain');
    echo 'ok';

    return;
}

set_time_limit(0);
ignore_user_abort(true);

$work = getenv('WORK_DIR') ?: sys_get_temp_dir();
$jobFile = $work.'/job-'.bin2hex(random_bytes(6)).'.json';
$resultFile = $jobFile.'.result';
file_put_contents($jobFile, file_get_contents('php://input'));

header('Content-Type: text/plain');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$process = proc_open(
    [PHP_BINARY, '-d', 'max_execution_time=0', '-d', 'memory_limit=512M', __DIR__.'/job.php', $jobFile, $resultFile],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', 'php://stderr', 'w'], 2 => ['file', 'php://stderr', 'w']],
    $pipes,
);

if (! is_resource($process)) {
    echo json_encode(['ok' => false, 'error' => 'Could not start the job process.'])."\n";

    return;
}

$lastPing = time();
while (true) {
    $status = proc_get_status($process);
    if (! $status['running']) {
        break;
    }
    if (time() - $lastPing >= 10) {
        echo ' ';
        flush();
        $lastPing = time();
    }
    usleep(250_000);
}
proc_close($process);

$result = is_file($resultFile) ? trim((string) file_get_contents($resultFile)) : '';
if ($result === '') {
    $result = json_encode(['ok' => false, 'error' => 'The job exited without a result (exit code '.($status['exitcode'] ?? '?').').']);
}

@unlink($jobFile);
@unlink($resultFile);

echo "\n".$result."\n";
flush();
