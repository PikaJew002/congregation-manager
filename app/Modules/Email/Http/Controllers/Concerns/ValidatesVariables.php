<?php

namespace App\Modules\Email\Http\Controllers\Concerns;

use App\Modules\Email\Actions\RenderEmail;
use Illuminate\Validation\Rule;

trait ValidatesVariables
{
    /** How many variables one list may hold. */
    public const MAX_VARIABLES = 50;

    /**
     * Rules for a list of {key, value} variables under the given field.
     *
     * @return array<string, mixed>
     */
    protected function variableRules(string $field = 'variables'): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_VARIABLES],
            "{$field}.*.key" => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct', Rule::notIn(RenderEmail::RESERVED)],
            "{$field}.*.value" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function variableMessages(string $field = 'variables'): array
    {
        return [
            "{$field}.*.key.regex" => __('A variable name uses lowercase letters, numbers and underscores, and starts with a letter, like zoom_url.'),
            "{$field}.*.key.distinct" => __('Each variable needs its own name.'),
            "{$field}.*.key.not_in" => __('name, unsubscribe_url and date are filled in for you, so a variable cannot use them.'),
        ];
    }

    /**
     * The validated list, tidied: values never null.
     *
     * @param  array<int, array{key: string, value?: string|null}>|null  $variables
     * @return list<array{key: string, value: string}>
     */
    protected function cleanVariables(?array $variables): array
    {
        return array_values(array_map(fn (array $variable) => [
            'key' => $variable['key'],
            'value' => (string) ($variable['value'] ?? ''),
        ], $variables ?? []));
    }
}
