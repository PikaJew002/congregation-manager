<?php

namespace App\Modules\WeeklyEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $email_run_id
 * @property int|null $email_recipient_id
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property string $status
 * @property int $attempts
 * @property string|null $message_id
 * @property string|null $request_id
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $last_event
 * @property string|null $error
 * @property-read EmailRun $run
 * @property-read EmailRecipient|null $recipient
 */
class EmailDelivery extends Model
{
    protected $fillable = [
        'email_run_id',
        'email_recipient_id',
        'scheduled_at',
        'queued_at',
        'sent_at',
        'status',
        'attempts',
        'message_id',
        'request_id',
        'delivered_at',
        'last_event',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmailRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailRun::class, 'email_run_id');
    }

    /**
     * @return BelongsTo<EmailRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailRecipient::class, 'email_recipient_id');
    }
}
