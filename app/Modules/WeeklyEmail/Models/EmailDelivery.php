<?php

namespace App\Modules\WeeklyEmail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDelivery extends Model
{
    protected $fillable = [
        'email_run_id',
        'email_recipient_id',
        'scheduled_at',
        'sent_at',
        'status',
        'message_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
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
