<?php

namespace App\Modules\ServiceSlides\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

trait ResolvesPresentationUpload
{
    /**
     * Accept either an uploaded file or a Dropbox / Google Drive share link.
     *
     * @throws ValidationException
     */
    private function presentationUpload(
        Request $request,
        SlideLinkDownloader $links,
        SlideFormats $formats,
        ?string $expectedFormat = null,
        ?string $kind = null,
    ): UploadedFile {
        $request->validate([
            'file' => ['required_without:url', 'nullable', 'file', 'max:102400'],
            'url' => ['required_without:file', 'nullable', 'string', 'max:2000'],
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $field = 'file';
        } else {
            $result = $links->download((string) $request->input('url'));

            if (is_string($result)) {
                throw ValidationException::withMessages(['url' => $result]);
            }

            $file = $result;
            $field = 'url';
        }

        $rejection = $formats->rejection($file->getRealPath(), $expectedFormat, $kind);

        if ($rejection !== null) {
            throw ValidationException::withMessages([$field => $rejection]);
        }

        return $file;
    }
}
