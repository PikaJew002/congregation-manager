<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Support\SlideFileLinks;
use App\Support\Modules\ModuleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends the file behind a signed link from an MCP tool. The signature covers what to send, who asked, and for which
 * congregation; the same access the tool call needed is checked again here, so a link stops working as soon as
 * that person loses access, not only when it expires.
 */
class FileLinkController extends Controller
{
    public function __invoke(Request $request, ModuleAccess $access, SlideFileLinks $links, string $kind, int $id): StreamedResponse
    {
        $user = User::query()->find($request->integer('user'));
        $team = Team::query()->find($request->integer('team'));

        abort_unless($user && $team && $user->teamRole($team) !== null && $access->canUse($user, $team, 'service-slides'), 403);

        [$path, $name] = match ($kind) {
            'library' => $this->source($team, $id, (string) $request->query('file', 'formatted'), $links),
            'deck' => $this->deck($team, $id, $links),
            default => abort(404),
        };

        abort_unless(Storage::disk('files')->exists($path), 404);

        Log::info('Service slides file downloaded from a signed link.', [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'kind' => $kind,
            'id' => $id,
            'file' => $kind === 'library' ? $request->query('file', 'formatted') : null,
        ]);

        return Storage::disk('files')->download($path, $name);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function source(Team $team, int $id, string $file, SlideFileLinks $links): array
    {
        abort_unless(in_array($file, SlideFileLinks::FILES, true), 404);
        $source = SlideSource::query()->forTeam($team)->find($id);
        abort_unless($source instanceof SlideSource, 404);

        return [$links->sourcePath($source, $file), $links->sourceName($source, $file)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function deck(Team $team, int $id, SlideFileLinks $links): array
    {
        $deck = ServiceDeck::query()->whereHas('service', fn ($query) => $query->where('team_id', $team->id))->find($id);
        abort_unless($deck instanceof ServiceDeck && $links->deckDownloadable($deck), 404);

        return [(string) $deck->generated_path, $links->deckName($deck)];
    }
}
