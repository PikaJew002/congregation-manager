<?php

namespace App\Modules\ServiceSlides\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class OdpFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Upload an ODP file.');

            return;
        }

        $rejection = app(OdpInspector::class)->rejection($value->getRealPath());

        if ($rejection !== null) {
            $fail($rejection);
        }
    }
}
