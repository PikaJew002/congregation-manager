<?php

namespace App\Modules\ServiceSlides\Support;

use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Http\Controllers\DeckController;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Which stored file a download sends and what it is called, and short-lived signed links to it for MCP clients,
 * which cannot put a file on someone's computer themselves.
 */
class SlideFileLinks
{
    public const FILES = ['formatted', 'original'];

    public const LINK_MINUTES = 10;

    /**
     * The file decks are built from (formatted) or the upload as received (original).
     */
    public function sourcePath(SlideSource $source, string $file): string
    {
        return $file === 'original' ? $source->original_path : $source->file_path;
    }

    public function sourceName(SlideSource $source, string $file): string
    {
        $extension = pathinfo($this->sourcePath($source, $file), PATHINFO_EXTENSION) ?: $source->original_format;

        return Str::of($source->title)->replaceMatches('/[\/\\\\:*?"<>|]+/', '-')->trim().'.'.$extension;
    }

    public function deckDownloadable(ServiceDeck $deck): bool
    {
        return in_array($deck->status, ['ready', 'stale'], true) && filled($deck->generated_path);
    }

    public function deckName(ServiceDeck $deck): string
    {
        return DeckController::fileName($deck);
    }

    /**
     * @return array{url: string, expiresAt: string}
     */
    public function forSource(User $user, Team $team, SlideSource $source, string $file): array
    {
        return $this->link($user, $team, ['kind' => 'library', 'id' => $source->id, 'file' => $file]);
    }

    /**
     * @return array{url: string, expiresAt: string}
     */
    public function forDeck(User $user, Team $team, ServiceDeck $deck): array
    {
        return $this->link($user, $team, ['kind' => 'deck', 'id' => $deck->id]);
    }

    /**
     * Signs what to send together with who asked and for which congregation, so a link cannot be edited to reach
     * another file or congregation, and its access can be checked again when it is opened.
     *
     * @param  array<string, int|string>  $target
     * @return array{url: string, expiresAt: string}
     */
    private function link(User $user, Team $team, array $target): array
    {
        $expires = Carbon::now()->addMinutes(self::LINK_MINUTES);

        return [
            'url' => URL::temporarySignedRoute('service-slides.file-link', $expires, [...$target, 'user' => $user->id, 'team' => $team->id]),
            'expiresAt' => $expires->toIso8601String(),
        ];
    }
}
