<?php

namespace App\Http\Requests\Teams;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTeamEmailSendingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('team'));
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('sending_domain');

        if (is_string($domain)) {
            $this->merge(['sending_domain' => strtolower(trim($domain)) ?: null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mailchannels_api_key' => ['nullable', 'string', 'max:255'],
            'clear_mailchannels_api_key' => ['sometimes', 'boolean'],
            'mailchannels_account_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sending_domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'daily_email_limit' => ['required', 'integer', 'between:1,1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mailchannels_account_id.regex' => 'The account ID has only letters, numbers, dashes and underscores.',
            'sending_domain.regex' => 'Enter a domain such as example.org, without https:// or an email address.',
        ];
    }
}
