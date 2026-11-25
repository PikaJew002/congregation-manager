<?php

/*
 * Runs exactly one media step and writes a JSON result file.
 *
 *   download   Zoom MP4 -> private files bucket (multipart) and public bucket.
 *              Re-uses the private copy when it already exists, so a retry does
 *              not need a live Zoom token.
 *   rendition  private MP4 -> one HLS rendition (360p, 480p, 720p, or 1080p) -> public.
 *   poster     private MP4 -> poster.jpg -> public.
 *
 * Usage: php job.php <job.json> <result.json>
 */

require __DIR__.'/../vendor/autoload.php';

use Aws\Exception\MultipartUploadException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

const PART_SIZE = 50 * 1024 * 1024;       // Container request bodies are capped at 100 MB.
const SINGLE_PUT_LIMIT = 90 * 1024 * 1024;

[$script, $jobFile, $resultFile] = $argv + [null, null, null];
$job = json_decode((string) file_get_contents((string) $jobFile), true) ?: [];
$work = rtrim(getenv('WORK_DIR') ?: sys_get_temp_dir(), '/').'/job-'.bin2hex(random_bytes(6));

try {
    mkdir($work, 0755, true);
    $result = run($job, $work);
    $result['ok'] = true;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    $result = ['ok' => false, 'error' => $exception->getMessage()];
} finally {
    deleteTree($work);
}

file_put_contents((string) $resultFile, json_encode($result, JSON_UNESCAPED_SLASHES));

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function run(array $job, string $work): array
{
    $directory = trim((string) ($job['directory'] ?? ''), '/');
    if ($directory === '') {
        throw new RuntimeException('Job is missing the recording directory.');
    }

    $s3 = s3();
    $private = env('R2_FILES_BUCKET');
    $public = env('R2_PUBLIC_BUCKET');
    $sourceKey = $directory.'/source.mp4';
    $source = $work.'/source.mp4';

    switch ($job['step'] ?? null) {
        case 'download':
            if (objectSize($s3, $private, $sourceKey) !== null) {
                log_line('Private source exists; reading it back instead of calling Zoom.');
                $s3->getObject(['Bucket' => $private, 'Key' => $sourceKey, 'SaveAs' => $source]);
            } else {
                downloadFromZoom((string) ($job['download_url'] ?? ''), (string) ($job['download_token'] ?? ''), $source);
                upload($s3, $private, $sourceKey, $source, ['ContentType' => 'video/mp4']);
            }

            if (objectSize($s3, $public, $sourceKey) !== filesize($source)) {
                upload($s3, $public, $sourceKey, $source, ['ContentType' => 'video/mp4']);
            }

            return ['source_path' => $sourceKey, 'height' => probeHeight($source), 'bytes' => filesize($source)];

        case 'rendition':
            $label = (string) ($job['rendition'] ?? '');
            if (! in_array($label, ['360p', '480p', '720p', '1080p'], true)) {
                throw new RuntimeException('Unknown rendition: '.$label);
            }
            $s3->getObject(['Bucket' => $private, 'Key' => $sourceKey, 'SaveAs' => $source]);
            $out = $work.'/hls';
            package($source, $out, $label);
            if (! is_file($out.'/'.$label.'/playlist.m3u8')) {
                throw new RuntimeException("The {$label} rendition was skipped for this source height.");
            }

            return ['files' => uploadTree($s3, $public, $directory, $out)];

        case 'poster':
            $s3->getObject(['Bucket' => $private, 'Key' => $sourceKey, 'SaveAs' => $source]);
            $out = $work.'/hls';
            package($source, $out, 'poster');
            if (! is_file($out.'/poster.jpg')) {
                throw new RuntimeException('FFmpeg did not write a poster frame.');
            }

            return ['files' => uploadTree($s3, $public, $directory, $out)];
    }

    throw new RuntimeException('Unknown step: '.json_encode($job['step'] ?? null));
}

function env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Container is missing {$name}.");
    }

    return $value;
}

function log_line(string $message): void
{
    fwrite(STDERR, '['.gmdate('c').'] '.$message."\n");
}

function s3(): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => getenv('R2_REGION') ?: 'auto',
        'endpoint' => env('R2_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => env('R2_ACCESS_KEY_ID'), 'secret' => env('R2_SECRET_ACCESS_KEY')],
        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
    ]);
}

function objectSize(S3Client $s3, string $bucket, string $key): ?int
{
    try {
        return (int) $s3->headObject(['Bucket' => $bucket, 'Key' => $key])['ContentLength'];
    } catch (S3Exception $exception) {
        if ($exception->getStatusCode() === 404) {
            return null;
        }
        throw $exception;
    }
}

/**
 * @param  array<string, string>  $params
 */
function upload(S3Client $s3, string $bucket, string $key, string $file, array $params): void
{
    $size = filesize($file);
    log_line("Uploading {$key} to {$bucket} ({$size} bytes).");

    if ($size <= SINGLE_PUT_LIMIT) {
        $s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'SourceFile' => $file] + $params);

        return;
    }

    // Resume from the parts that already landed if one part fails (a dropped
    // TLS connection partway through a 1 GB upload should not lose the rest).
    $options = ['bucket' => $bucket, 'key' => $key, 'part_size' => PART_SIZE, 'concurrency' => 1, 'params' => $params];
    for ($attempt = 1; ; $attempt++) {
        try {
            (new MultipartUploader($s3, $file, $options))->upload();

            return;
        } catch (MultipartUploadException $exception) {
            if ($attempt >= 4) {
                throw $exception;
            }
            log_line("Multipart upload attempt {$attempt} failed; resuming. ".$exception->getMessage());
            $options['state'] = $exception->getState();
            sleep(2 * $attempt);
        }
    }
}

function uploadTree(S3Client $s3, string $bucket, string $directory, string $root): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $name = $file->getFilename();
        $relative = substr($file->getPathname(), strlen($root) + 1);
        upload($s3, $bucket, $directory.'/'.$relative, $file->getPathname(), [
            'ContentType' => match (true) {
                str_ends_with($name, '.m3u8') => 'application/vnd.apple.mpegurl',
                str_ends_with($name, '.ts') => 'video/mp2t',
                str_ends_with($name, '.jpg') => 'image/jpeg',
                default => 'application/octet-stream',
            },
            'CacheControl' => str_ends_with($name, '.m3u8') ? 'public, max-age=60' : 'public, max-age=31536000',
        ]);
        $count++;
    }

    return $count;
}

function package(string $source, string $out, string $only): void
{
    $command = [env('HLS_SCRIPT'), $source, $out, $only];
    log_line('Running '.implode(' ', $command));
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes);
    $code = is_resource($process) ? proc_close($process) : -1;
    if ($code !== 0) {
        throw new RuntimeException("FFmpeg failed for {$only} (exit {$code}).");
    }
}

function probeHeight(string $file): ?int
{
    $output = shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 '.escapeshellarg($file));
    $height = (int) trim(strtok((string) $output, "\n") ?: '');

    return $height > 0 ? $height : null;
}

/**
 * Zoom's download URL redirects to a file host. Follow each hop ourselves and
 * send the webhook download token every time.
 */
function downloadFromZoom(string $url, string $token, string $destination): void
{
    if ($url === '' || $token === '') {
        throw new RuntimeException('No private copy exists and Zoom did not provide a download URL and token.');
    }

    $current = $url;
    for ($hop = 0; $hop < 5; $hop++) {
        if (parse_url($current, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Zoom download URL must use https.');
        }

        $handle = fopen($destination, 'w');
        $headers = [];
        $curl = curl_init($current);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 7000,
            CURLOPT_FILE => $handle,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false) {
            throw new RuntimeException('Zoom download failed: '.$error);
        }

        if ($status >= 300 && $status < 400) {
            $current = absoluteUrl($current, $headers['location'] ?? '');

            continue;
        }

        if ($status >= 400) {
            throw new RuntimeException('Zoom download failed: '.$status);
        }

        $head = strtolower((string) file_get_contents($destination, false, null, 0, 64));
        $html = str_contains(strtolower($headers['content-type'] ?? ''), 'text/html')
            || str_contains($head, '<html') || str_contains($head, '<!doctype');
        if ($html || ! str_contains($head, 'ftyp')) {
            throw new RuntimeException('Zoom returned a web page instead of the recording.');
        }

        log_line('Downloaded '.filesize($destination).' bytes from Zoom.');

        return;
    }

    throw new RuntimeException('Zoom download redirected too many times.');
}

function absoluteUrl(string $current, string $location): string
{
    if ($location === '') {
        throw new RuntimeException('Zoom download redirect was missing a location.');
    }
    if (str_starts_with($location, '//')) {
        return 'https:'.$location;
    }
    if (parse_url($location, PHP_URL_SCHEME)) {
        return $location;
    }

    $parts = parse_url($current);
    $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    if (str_starts_with($location, '/')) {
        return $origin.$location;
    }
    $path = $parts['path'] ?? '/';

    return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
}

function deleteTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}
