<?php

namespace App\Modules\ServiceSlides\Support;

use Illuminate\Http\UploadedFile;

/**
 * Turns a base64 or data-URL payload into an uploaded presentation file.
 */
class EncodedUpload
{
    public function decode(string $encoded): UploadedFile|string
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
}
