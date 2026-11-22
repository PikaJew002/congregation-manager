<?php

namespace App\Modules\ServiceSlides\Support;

use ZipArchive;

/**
 * Library items and templates are ODP (songs, saved from Impress) or PPTX (liturgy, saved from PowerPoint).
 * A deck merges files of its template's format only.
 */
class SlideFormats
{
    public const LABELS = ['odp' => 'ODP', 'pptx' => 'PPTX'];

    public function __construct(
        private OdpInspector $odpInspector,
        private PptxInspector $pptxInspector,
        private OdpDeckBuilder $odpBuilder,
        private PptxDeckBuilder $pptxBuilder,
    ) {}

    public function detect(string $path): ?string
    {
        return $this->inspect($path)[0];
    }

    /**
     * Songs are ODP; liturgy is PPTX. Other kinds (and templates without a default kind) accept either.
     */
    public function formatForKind(?string $kind): ?string
    {
        return match ($kind) {
            'song' => 'odp',
            'liturgy' => 'pptx',
            default => null,
        };
    }

    public function kindRequiresFormat(?string $kind, string $format): ?string
    {
        $expected = $this->formatForKind($kind);

        if ($expected === null || $expected === $format) {
            return null;
        }

        return $kind === 'song'
            ? 'Songs must be ODP files.'
            : 'Liturgy must be PPTX files.';
    }

    /**
     * Null when the file is an ODP or PPTX presentation (of the expected format, when given), or a message explaining why not.
     */
    public function rejection(string $path, ?string $expected = null, ?string $kind = null): ?string
    {
        [$format, $problem] = $this->inspect($path);

        if ($problem !== null) {
            return $problem;
        }

        $expected ??= $this->formatForKind($kind);

        if ($expected !== null && $format !== $expected) {
            if ($kindMismatch = $this->kindRequiresFormat($kind, (string) $format)) {
                return $kindMismatch;
            }

            return 'This uses a '.self::LABELS[$expected].' file, so the replacement must be '.self::LABELS[$expected].' too.';
        }

        return null;
    }

    public function inspector(string $format): SlideInspector
    {
        return $format === 'pptx' ? $this->pptxInspector : $this->odpInspector;
    }

    public function builder(string $format): DeckBuilder
    {
        return $format === 'pptx' ? $this->pptxBuilder : $this->odpBuilder;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function inspect(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $header = (string) @file_get_contents($path, length: 8);

            return [null, $header === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
                ? 'This is an old PowerPoint file (.ppt). Save it as PPTX first.'
                : 'Upload an ODP or PPTX presentation.'];
        }

        $mimetype = $zip->getFromName('mimetype');
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $hasPresentation = $zip->locateName('ppt/presentation.xml') !== false;
        $zip->close();

        if ($contentTypes !== false && $hasPresentation) {
            return str_contains($contentTypes, PptxPackage::MAIN_CONTENT_TYPE)
                ? ['pptx', null]
                : [null, 'Save it as a PowerPoint Presentation (.pptx) first. Templates, shows, and macro files are not supported.'];
        }

        if ($mimetype !== false && trim($mimetype) === OdpInspector::MIMETYPE) {
            return ['odp', null];
        }

        return [null, 'Upload an ODP or PPTX presentation.'];
    }
}
