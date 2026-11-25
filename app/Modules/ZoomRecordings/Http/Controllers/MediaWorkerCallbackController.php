<?php

namespace App\Modules\ZoomRecordings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ZoomRecordings\Actions\MediaWorker;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Cloudflare media worker reports each finished step here, signed with
 * MEDIA_WORKER_SECRET. Success moves to the next step; failure sets
 * failed_step so the retry button resumes there.
 */
class MediaWorkerCallbackController extends Controller
{
    public function __invoke(Request $request, MediaWorker $worker): JsonResponse
    {
        $secret = (string) config('services.media_worker.secret');
        $signature = (string) $request->header(MediaWorker::SIGNATURE_HEADER);
        abort_if($secret === '', 404);
        abort_unless(hash_equals(MediaWorker::sign($request->getContent()), strtolower($signature)), 401);
        abort_unless(abs(time() - (int) $request->json('sent_at')) < 300, 401);

        $recording = ZoomRecording::query()->findOrFail((int) $request->json('recording_id'));
        $step = (string) $request->json('step');
        $key = $step === 'rendition' ? 'rendition:'.$request->json('rendition') : $step;

        if ($recording->media_step !== $key) {
            return response()->json(['ignored' => true, 'expected' => $recording->media_step], 409);
        }

        if (! $request->json('ok')) {
            $worker->fail($recording, $key, (string) ($request->json('error') ?: 'Unknown media worker error.'));

            return response()->json(['ok' => true]);
        }

        if ($step === 'download') {
            $recording->update([
                'source_path' => (string) $request->json('source_path', $recording->directory().'/source.mp4'),
                'source_height' => $request->json('height'),
                'status' => 'downloaded',
            ]);
            $recording->note('Downloaded source MP4'.($recording->source_height ? " ({$recording->source_height}p)." : '.'));
        } else {
            $recording->note("Media worker finished {$key}.");
        }

        $worker->advance($recording, $key);

        return response()->json(['ok' => true]);
    }
}
