<?php

namespace App\Modules\ZoomRecordings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ZoomRecordings\Actions\DeleteRecording;
use App\Modules\ZoomRecordings\Actions\DispatchRecordingPipeline;
use App\Modules\ZoomRecordings\Actions\UpdateRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ZoomRecordingController extends Controller
{
    public function index(Team $current_team): Response
    {
        return Inertia::render('zoom-recordings/Index', [
            'recordings' => ZoomRecording::query()->forTeam($current_team)->latest()->limit(100)->get(),
            'publicBaseUrl' => ZoomRecordingSetting::forTeam($current_team)->public_base_url,
            'timezone' => ZoomRecordingSetting::forTeam($current_team)->timezone,
            'defaultService' => ZoomRecording::DEFAULT_SERVICE,
        ]);
    }

    public function update(Request $request, Team $current_team, ZoomRecording $recording, UpdateRecording $updater): RedirectResponse
    {
        abort_unless($recording->team_id === $current_team->id, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'speaker' => ['nullable', 'string', 'max:255'],
            'torah_portion' => ['nullable', 'string', 'max:255'],
            'archived' => ['required', 'boolean'],
        ]);
        $data['archived'] = (bool) $data['archived'];
        $updater->handle($recording, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recording updated.')]);

        return back();
    }

    public function destroy(Team $current_team, ZoomRecording $recording, DeleteRecording $delete): RedirectResponse
    {
        abort_unless($recording->team_id === $current_team->id, 404);

        if (! DeleteRecording::canDelete($recording)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This recording is still processing. Delete it once it has finished or failed.')]);

            return back();
        }

        $delete->handle($recording);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recording deleted.')]);

        return back();
    }

    public function retry(Team $current_team, ZoomRecording $recording, DispatchRecordingPipeline $pipeline): RedirectResponse
    {
        abort_unless($recording->team_id === $current_team->id, 404);
        abort_unless($recording->status === 'failed', 422);

        $pipeline->handle($recording, $recording->failed_step);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retry queued.')]);

        return back();
    }

    public function settings(Team $current_team): Response
    {
        $settings = ZoomRecordingSetting::forTeam($current_team);

        return Inertia::render('zoom-recordings/Settings', [
            'settings' => [
                'r2_prefix' => $settings->r2_prefix,
                'public_base_url' => $settings->public_base_url,
                'preferred_recording_type' => $settings->preferred_recording_type,
                'timezone' => $settings->timezone,
                'has_webhook_secret' => filled($settings->webhook_secret),
                'webhook_url' => route('zoom-recordings.webhook', $current_team->uuid),
            ],
        ]);
    }

    public function updateSettings(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'webhook_secret' => ['nullable', 'string'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
            'r2_prefix' => ['required', 'string', 'max:255'],
            'public_base_url' => ['nullable', 'url'],
            'preferred_recording_type' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'timezone'],
        ]);

        $settings = ZoomRecordingSetting::forTeam($current_team);

        if ($data['clear_webhook_secret'] ?? false) {
            $data['webhook_secret'] = null;
        } elseif (blank($data['webhook_secret'] ?? null)) {
            unset($data['webhook_secret']);
        }

        unset($data['clear_webhook_secret']);
        $settings->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recording settings saved.')]);

        return back();
    }
}
