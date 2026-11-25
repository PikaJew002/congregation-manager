<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;

/**
 * Changes a recording's details or archives / restores it, then refreshes the
 * public feed. Shared by the Recordings page and the MCP tools.
 */
class UpdateRecording
{
    public const FIELDS = ['title', 'service', 'speaker', 'torah_portion'];

    /**
     * @param  array<string, mixed>  $changes  Any of FIELDS, plus "archived" (bool). Keys left out are unchanged.
     */
    public function handle(ZoomRecording $recording, array $changes): ZoomRecording
    {
        $data = array_intersect_key($changes, array_flip(self::FIELDS));

        foreach (['service', 'speaker', 'torah_portion'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                $data[$field] = null;
            }
        }

        if (array_key_exists('archived', $changes)) {
            // The server stamps the time. Archiving an already archived
            // recording keeps its original date.
            $data['archived_at'] = $changes['archived'] ? ($recording->archived_at ?? now()) : null;
        }

        $recording->update($data);

        if (filled($recording->hls_path)) {
            (new UpdateManifest($recording->id))->handle();
        }

        return $recording;
    }
}
