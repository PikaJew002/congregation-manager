<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Support\SlideFormats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Psr\Http\Message\UriInterface;
use Throwable;

class AddLibraryItem extends Tool
{
    protected string $description = 'Add an ODP or PPTX file to this congregation\'s slide library. Songs are ODP; liturgy is usually PPTX, and a deck only takes files in its template\'s format. Send the file as base64, or send a Dropbox download link. When title is omitted, line one of the first slide supplies it; for songs, line two supplies a missing author.';

    public function __construct(private CongregationContext $context, private SlideFiles $files, private SlideFormats $formats) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Library title. Omit to use the first line of the first slide.'),
            'kind' => $schema->string()->description('song, liturgy, or other.')->required(),
            'file' => $schema->string()->description('Base64-encoded ODP or PPTX file, optionally as a data URL. Send this or url.'),
            'url' => $schema->string()->description('https Dropbox link to the ODP or PPTX file. A shared link is accepted.'),
            'author' => $schema->string()->description('Song artist. Omit to use the second line of the first slide of a song when it exists.'),
            'ccli' => $schema->string()->description('Optional CCLI number.'),
            'notes' => $schema->string()->description('Optional notes.'),
            'aliases' => $schema->array()->items($schema->string())->description('Optional alternate titles.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(config('modules.slide_kinds')))],
            'file' => ['required_without:url', 'nullable', 'string'],
            'url' => ['required_without:file', 'nullable', 'string', 'max:2000'],
            'author' => ['nullable', 'string', 'max:255'],
            'ccli' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:255'],
        ]);

        $upload = filled($data['file'] ?? null)
            ? $this->decodeUpload($data['file'])
            : $this->downloadDropbox($data['url']);

        if (is_string($upload)) {
            return Response::error($upload);
        }

        try {
            $rejection = $this->formats->rejection($upload->getRealPath());

            if ($rejection !== null) {
                return Response::error($rejection);
            }

            $format = (string) $this->formats->detect($upload->getRealPath());
            $lines = $this->formats->inspector($format)->firstSlideLines($upload->getRealPath());
            $title = trim((string) ($data['title'] ?? ''));
            $author = trim((string) ($data['author'] ?? ''));

            if ($title === '') {
                $title = mb_substr($lines[0] ?? '', 0, 255);
            }

            if ($author === '' && $data['kind'] === 'song' && isset($lines[1])) {
                $author = mb_substr($lines[1], 0, 255);
            }

            if ($title === '') {
                return Response::error('The first slide has no title line.');
            }

            $existing = SlideSource::query()->forTeam($team)->where('title', $title)->where('original_format', $format)->where('archived', false)->first();

            if ($existing) {
                return Response::error($title.' is already in this congregation\'s library as '.SlideFormats::LABELS[$format].'.');
            }

            $source = $this->files->createSource($team, $upload, [
                'title' => $title,
                'kind' => $data['kind'],
                'author' => $author !== '' ? $author : null,
                'ccli' => $data['ccli'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['aliases'] ?? [] as $alias) {
                $alias = trim($alias);

                if ($alias !== '') {
                    $source->aliases()->firstOrCreate(['alias' => $alias]);
                }
            }
        } finally {
            @unlink($upload->getRealPath());
        }

        $source->load('aliases');

        return Response::json([
            'id' => $source->id,
            'title' => $source->title,
            'author' => $source->author,
            'kind' => $source->kind,
            'format' => $source->original_format,
            'slide_count' => $source->slide_count,
            'aliases' => $source->aliases->pluck('alias')->all(),
        ]);
    }

    private function decodeUpload(string $encoded): UploadedFile|string
    {
        $encoded = preg_replace('/\s+/', '', $encoded) ?? '';

        if (str_starts_with($encoded, 'data:') && str_contains($encoded, ',')) {
            $encoded = substr($encoded, (int) strpos($encoded, ',') + 1);
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return 'The file must be a base64-encoded ODP or PPTX.';
        }

        if (strlen($bytes) > 102400 * 1024) {
            return 'The file is larger than 100 MB.';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'library-');

        if ($temporary === false) {
            return 'The file could not be stored.';
        }

        file_put_contents($temporary, $bytes);

        return new UploadedFile($temporary, 'library', null, null, true);
    }

    private function downloadDropbox(string $url): UploadedFile|string
    {
        try {
            $downloadUrl = $this->dropboxDownloadUrl($url);
            $response = Http::withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, UriInterface $uri): void {
                        if ($uri->getScheme() !== 'https' || ! $this->isDropboxHost($uri->getHost())) {
                            throw new ConnectionException('The Dropbox link redirected away from Dropbox.');
                        }
                    },
                ],
                'progress' => function ($downloadTotal, $downloaded, $uploadTotal, $uploaded): void {
                    if ($downloaded > 102400 * 1024) {
                        throw new ConnectionException('The file is larger than 100 MB.');
                    }
                },
            ])->timeout(60)->get($downloadUrl);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            return $this->downloadFailure($exception);
        }

        if (! $response->successful()) {
            return 'Dropbox did not return the file.';
        }

        $bytes = $response->body();

        if ($bytes === '' || str_starts_with(ltrim($bytes), '<')) {
            return 'Dropbox returned a web page instead of the file. The link needs to be shared so anyone with it can download.';
        }

        if (strlen($bytes) > 102400 * 1024) {
            return 'The file is larger than 100 MB.';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'library-');

        if ($temporary === false) {
            return 'The file could not be stored.';
        }

        file_put_contents($temporary, $bytes);

        return new UploadedFile($temporary, 'library', null, null, true);
    }

    private function dropboxDownloadUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || ! $this->isDropboxHost((string) ($parts['host'] ?? '')) || ! isset($parts['path'])) {
            throw new InvalidArgumentException('Send an https Dropbox link.');
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['dl'] = '1';

        return 'https://'.strtolower((string) $parts['host']).$parts['path'].'?'.http_build_query($query);
    }

    private function isDropboxHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'dropbox.com'
            || $host === 'dropboxusercontent.com'
            || str_ends_with($host, '.dropbox.com')
            || str_ends_with($host, '.dropboxusercontent.com');
    }

    private function downloadFailure(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'larger than 100 MB')) {
            return 'The file is larger than 100 MB.';
        }

        if (str_contains($message, 'redirected away from Dropbox')) {
            return 'The Dropbox link redirected away from Dropbox.';
        }

        return 'The Dropbox link could not be downloaded.';
    }
}
