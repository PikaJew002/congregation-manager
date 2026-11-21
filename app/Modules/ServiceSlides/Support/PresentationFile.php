<?php

namespace App\Modules\ServiceSlides\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class PresentationFile implements ValidationRule
{
    public function __construct(private ?string $format = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Upload an ODP or PPTX file.');

            return;
        }

        $rejection = app(SlideFormats::class)->rejection($value->getRealPath(), $this->format);

        if ($rejection !== null) {
            $fail($rejection);
        }
    }
}
