<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_personal' => false,
        ];
    }

    /**
     * Indicate that the team is a personal team.
     */
    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_personal' => true,
        ]);
    }

    /**
     * Indicate that the team has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * The congregation has its own MailChannels account set up, and the last
     * domain check passed.
     *
     * @return array<string, mixed>
     */
    public static function emailSendingAttributes(string $domain = 'shul.example', string $selector = 'cm202611'): array
    {
        return [
            'mailchannels_api_key' => 'congregation-key',
            'mailchannels_account_id' => 'acct_1',
            'sending_domain' => $domain,
            'dkim_selector' => $selector,
            'dkim_dns_record' => ['name' => "{$selector}._domainkey.{$domain}", 'type' => 'TXT', 'value' => 'v=DKIM1; k=rsa; p=abc'],
            'domain_check' => [
                'dkim' => [['dkim_domain' => $domain, 'dkim_selector' => $selector, 'verdict' => 'passed', 'reason' => null]],
                'spf' => ['verdict' => 'passed', 'reason' => null, 'spfRecord' => 'v=spf1 include:relay.mailchannels.net ~all'],
                'domain_lockdown' => ['verdict' => 'passed', 'reason' => null],
            ],
            'domain_checked_at' => now(),
        ];
    }

    /**
     * Indicate that the team can send email through its own MailChannels account.
     */
    public function readyToSendEmail(): static
    {
        return $this->state(fn (array $attributes) => self::emailSendingAttributes());
    }
}
