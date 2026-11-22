<?php

namespace App\Modules\ServiceSlides\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Turns a Dropbox or Google Drive share/edit/view link into a temporary upload of the shared file.
 */
class SlideLinkDownloader
{
    private const MAX_BYTES = 102400 * 1024;

    /**
     * @return UploadedFile|string Error message when the link cannot be downloaded.
     */
    public function download(string $url): UploadedFile|string
    {
        try {
            [$provider, $downloadUrl] = $this->resolve($url);
            $response = $this->get($downloadUrl, $provider);

            if ($provider === 'drive' && $this->looksLikeHtml($response->body())) {
                $confirmUrl = $this->driveConfirmUrl($downloadUrl, $response);

                if ($confirmUrl !== null) {
                    $response = $this->get($confirmUrl, $provider);
                }
            }
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }

        if (! $response->successful()) {
            return 'The link did not return the file.';
        }

        $bytes = $response->body();

        if ($bytes === '' || $this->looksLikeHtml($bytes)) {
            return 'The link returned a web page instead of the file. Share it so anyone with the link can download.';
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return 'The file is larger than 100 MB.';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'library-');

        if ($temporary === false) {
            return 'The file could not be stored.';
        }

        file_put_contents($temporary, $bytes);

        return new UploadedFile($temporary, $this->filename($response, $downloadUrl), null, null, true);
    }

    /**
     * @return array{0: 'dropbox'|'drive', 1: string}
     */
    public function resolve(string $url): array
    {
        $parts = $this->httpsParts($url);
        $host = strtolower((string) $parts['host']);

        if ($this->isDropboxHost($host)) {
            return ['dropbox', $this->dropboxDownloadUrl($parts)];
        }

        if ($this->isDriveHost($host)) {
            return ['drive', $this->driveDownloadUrl($parts, $url)];
        }

        throw new InvalidArgumentException('Send an https Dropbox or Google Drive link.');
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function dropboxDownloadUrl(array $parts): string
    {
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        unset($query['st']);
        $query['dl'] = '1';

        return 'https://'.strtolower((string) $parts['host']).$parts['path'].'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function driveDownloadUrl(array $parts, string $original): string
    {
        $id = $this->driveId($parts, $original);

        if ($id === null) {
            throw new InvalidArgumentException('That Google Drive link does not include a file id.');
        }

        return 'https://drive.google.com/uc?export=download&id='.rawurlencode($id);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function driveId(array $parts, string $original): ?string
    {
        $path = (string) ($parts['path'] ?? '');

        if (preg_match('#/(?:file|presentation|document|spreadsheets)/d/([a-zA-Z0-9_-]+)#', $path, $matches)) {
            return $matches[1];
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        if (filled($query['id'] ?? null) && is_string($query['id'])) {
            return $query['id'];
        }

        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $original, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function driveConfirmUrl(string $downloadUrl, Response $response): ?string
    {
        $body = $response->body();
        $confirm = null;

        if (preg_match('/name="confirm"\s+value="([^"]+)"/', $body, $matches)
            || preg_match('/confirm=([0-9A-Za-z_]+)/', $body, $matches)) {
            $confirm = $matches[1];
        }

        foreach ($response->cookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'download_warning')) {
                $confirm = $cookie->getValue();
            }
        }

        if ($confirm === null || $confirm === '') {
            $confirm = 't';
        }

        $parts = parse_url($downloadUrl);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['confirm'] = $confirm;

        return 'https://drive.google.com/uc?'.http_build_query($query);
    }

    private function get(string $url, string $provider): Response
    {
        return Http::withOptions([
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['https'],
                'on_redirect' => function ($request, $response, UriInterface $uri) use ($provider): void {
                    if ($uri->getScheme() !== 'https' || ! $this->allowsRedirect($provider, $uri->getHost())) {
                        throw new ConnectionException('The link redirected away from '.($provider === 'drive' ? 'Google Drive' : 'Dropbox').'.');
                    }
                },
            ],
            'progress' => function ($downloadTotal, $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new ConnectionException('The file is larger than 100 MB.');
                }
            },
        ])->timeout(60)->get($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpsParts(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || ! isset($parts['host'])
            || ! isset($parts['path'])) {
            throw new InvalidArgumentException('Send an https Dropbox or Google Drive link.');
        }

        return $parts;
    }

    private function allowsRedirect(string $provider, string $host): bool
    {
        return $provider === 'drive' ? $this->isDriveHost($host) : $this->isDropboxHost($host);
    }

    private function isDropboxHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'dropbox.com'
            || $host === 'dropboxusercontent.com'
            || str_ends_with($host, '.dropbox.com')
            || str_ends_with($host, '.dropboxusercontent.com');
    }

    private function isDriveHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'drive.google.com'
            || $host === 'docs.google.com'
            || $host === 'drive.usercontent.google.com'
            || $host === 'googleusercontent.com'
            || str_ends_with($host, '.googleusercontent.com')
            || str_ends_with($host, '.googledrive.com');
    }

    private function looksLikeHtml(string $bytes): bool
    {
        $start = ltrim($bytes);

        return str_starts_with($start, '<') || str_starts_with($start, '<!');
    }

    private function filename(Response $response, string $downloadUrl): string
    {
        $disposition = $response->header('Content-Disposition');

        if (is_string($disposition) && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
            $name = rawurldecode(trim($matches[1], " \t\"'"));

            if ($name !== '') {
                return basename(str_replace(['\\', '/'], '-', $name));
            }
        }

        $path = parse_url($downloadUrl, PHP_URL_PATH);

        return is_string($path) && basename($path) !== '' && basename($path) !== 'uc' ? basename($path) : 'library';
    }

    private function failure(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'larger than 100 MB')) {
            return 'The file is larger than 100 MB.';
        }

        if (str_contains($message, 'redirected away from')) {
            return $message;
        }

        return 'The link could not be downloaded.';
    }
}
