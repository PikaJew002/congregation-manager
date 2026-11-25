<?php

namespace App\Modules\ZoomRecordings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ZoomRecordings\Actions\DispatchRecordingPipeline;
use App\Modules\ZoomRecordings\Jobs\LookUpRecordingDate;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use App\Support\Modules\ModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ZoomWebhookController extends Controller
{
    public function __invoke(Request $request, Team $team, ModuleAccess $access, DispatchRecordingPipeline $pipeline): JsonResponse|Response
    {
        abort_unless($access->isEnabled($team, 'zoom-recordings'), 404);

        $settings = ZoomRecordingSetting::forTeam($team);
        $secret = (string) $settings->webhook_secret;
        abort_if($secret === '', 403);
        $payload = $request->json()->all();

        if (($payload['event'] ?? null) === 'endpoint.url_validation') {
            $plain = (string) data_get($payload, 'payload.plainToken');

            return response()->json([
                'plainToken' => $plain,
                'encryptedToken' => hash_hmac('sha256', $plain, $secret),
            ]);
        }

        $this->verifySignature($request, $secret);

        if (($payload['event'] ?? null) !== 'recording.completed') {
            return response('ignored', 200);
        }

        $object = data_get($payload, 'payload.object', []);
        $file = $this->preferredFile($object, $settings->preferred_recording_type);
        $downloadUrl = $file['download_url'] ?? null;
        $downloadToken = data_get($payload, 'download_token') ?? data_get($payload, 'payload.download_token');

        if (! $file || empty($object['uuid']) || blank($downloadUrl) || blank($downloadToken)) {
            return response('no recording file', 200);
        }

        $recording = ZoomRecording::query()->firstOrCreate(
            ['team_id' => $team->id, 'meeting_uuid' => $object['uuid']],
            [
                'meeting_id' => $object['id'] ?? null,
                'title' => $object['topic'] ?? 'Recording',
                'recorded_at' => $object['start_time'] ?? null,
                'duration_minutes' => $object['duration'] ?? null,
                'download_url' => $downloadUrl,
                'download_token' => $downloadToken,
                'recording_file_id' => $file['id'] ?? null,
                'status' => 'received',
            ],
        );

        if (! $recording->wasRecentlyCreated && blank($recording->source_path)) {
            $recording->update([
                'download_url' => $downloadUrl,
                'download_token' => $downloadToken,
                'recording_file_id' => $file['id'] ?? null,
            ]);
        }

        if ($recording->wasRecentlyCreated) {
            LookUpRecordingDate::dispatch($recording->id)->afterResponse();
            $pipeline->handle($recording);
        }

        return response('ok', 200);
    }

    private function verifySignature(Request $request, string $secret): void
    {
        $timestamp = (string) $request->header('x-zm-request-timestamp');
        $signature = (string) $request->header('x-zm-signature');
        abort_unless($timestamp !== '' && abs(time() - (int) $timestamp) < 300, 401);
        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function preferredFile(array $object, string $preferred): ?array
    {
        $files = collect($object['recording_files'] ?? [])->filter(fn ($file) => ($file['file_type'] ?? null) === 'MP4');
        $match = $files->firstWhere('recording_type', $preferred);

        return $match ?: $files->first();
    }
}
