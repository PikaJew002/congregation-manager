<?php

namespace App\Modules\ServiceSlides\Support;

use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Preset
{
    /**
     * Intro slides, then any separator slide, then one repeating slot, then title slides.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(SlideTemplate $template): array
    {
        $slides = $template->slides()->where('missing', false)->get();
        $separator = $slides->firstWhere('role', 'separator');
        $kind = $template->default_kind ?? 'song';
        $steps = [];

        foreach ($slides->where('role', 'intro') as $slide) {
            $steps[] = ['type' => 'template_slide', 'slide_id' => $slide->id];
        }

        if ($separator) {
            $steps[] = ['type' => 'template_slide', 'slide_id' => $separator->id];
        }

        $steps[] = [
            'type' => 'slot',
            'key' => $kind === 'song' ? 'songs' : 'items',
            'label' => $kind === 'song' ? 'Songs' : 'Items',
            'kind' => $kind,
            'repeat' => true,
            'separator_slide_id' => $separator?->id,
            'default_source_ids' => [],
        ];

        foreach ($slides->where('role', 'title') as $slide) {
            $steps[] = ['type' => 'template_slide', 'slide_id' => $slide->id];
        }

        return $steps;
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function normalize(array $steps, SlideTemplate $template): array
    {
        $slideIds = $template->slides()->pluck('id')->all();
        $kinds = array_keys(config('modules.slide_kinds'));

        $validator = Validator::make(['steps' => $steps], [
            'steps' => ['present', 'array'],
            'steps.*.type' => ['required', Rule::in(['template_slide', 'slot'])],
            'steps.*.slide_id' => ['required_if:steps.*.type,template_slide', 'nullable', 'integer', Rule::in($slideIds)],
            'steps.*.key' => ['required_if:steps.*.type,slot', 'nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'steps.*.label' => ['required_if:steps.*.type,slot', 'nullable', 'string', 'max:100'],
            'steps.*.kind' => ['nullable', Rule::in($kinds)],
            'steps.*.repeat' => ['nullable', 'boolean'],
            'steps.*.separator_slide_id' => ['nullable', 'integer', Rule::in($slideIds)],
            'steps.*.default_source_ids' => ['nullable', 'array'],
            'steps.*.default_source_ids.*' => ['integer'],
        ], [
            'steps.*.key.regex' => 'Slot keys use lowercase letters, numbers, dashes, and underscores.',
        ]);

        $validator->after(function ($validator) use ($steps, $template) {
            $keys = [];
            $sourceIds = SlideSource::query()->forTeam($template->team)->pluck('id')->all();

            foreach ($steps as $index => $step) {
                if (($step['type'] ?? null) !== 'slot') {
                    continue;
                }

                $key = $step['key'] ?? null;
                if ($key !== null && in_array($key, $keys, true)) {
                    $validator->errors()->add("steps.{$index}.key", "The slot key \"{$key}\" is used twice.");
                }
                $keys[] = $key;

                foreach ($step['default_source_ids'] ?? [] as $sourceId) {
                    if (! in_array((int) $sourceId, $sourceIds, true)) {
                        $validator->errors()->add("steps.{$index}.default_source_ids", 'A default item is not in this library.');
                    }
                }
            }
        });

        $validator->validate();

        return array_map(fn (array $step) => $step['type'] === 'template_slide'
            ? ['type' => 'template_slide', 'slide_id' => (int) $step['slide_id']]
            : [
                'type' => 'slot',
                'key' => $step['key'],
                'label' => $step['label'],
                'kind' => $step['kind'] ?? null,
                'repeat' => (bool) ($step['repeat'] ?? false),
                'separator_slide_id' => isset($step['separator_slide_id']) ? (int) $step['separator_slide_id'] : null,
                'default_source_ids' => array_values(array_map('intval', $step['default_source_ids'] ?? [])),
            ], array_values($steps));
    }

    /**
     * Preset steps after removing references to template slides that no longer exist.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(SlideTemplate $template): array
    {
        if ($template->preset === null) {
            return $this->suggest($template);
        }

        $present = $template->slides()->where('missing', false)->pluck('id')->all();
        $steps = [];

        foreach ($template->preset as $step) {
            if ($step['type'] === 'template_slide') {
                if (in_array($step['slide_id'], $present, true)) {
                    $steps[] = $step;
                }

                continue;
            }

            if (! in_array($step['separator_slide_id'] ?? null, $present, true)) {
                $step['separator_slide_id'] = null;
            }
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * Deck entries for one slot filled with the given library items.
     *
     * @param  array<string, mixed>  $slot
     * @param  iterable<SlideSource>  $sources
     * @return list<array<string, mixed>>
     */
    public function slotEntries(array $slot, iterable $sources): array
    {
        $entries = [];

        foreach ($sources as $source) {
            $entries[] = [
                'entry_type' => 'source',
                'slide_source_id' => $source->id,
                'slide_template_slide_id' => null,
                'slot_key' => $slot['key'],
                'auto' => false,
            ];

            if (! empty($slot['separator_slide_id'])) {
                $entries[] = [
                    'entry_type' => 'template_slide',
                    'slide_source_id' => null,
                    'slide_template_slide_id' => $slot['separator_slide_id'],
                    'slot_key' => $slot['key'],
                    'auto' => true,
                ];
            }

            if (! ($slot['repeat'] ?? false)) {
                break;
            }
        }

        return $entries;
    }
}
