export type RecipientStatus =
    | 'subscribed'
    | 'unsubscribed'
    | 'bounced'
    | 'complained'
    | 'suppressed';

export type EmailRecipient = {
    id: number;
    email: string;
    name: string | null;
    status: RecipientStatus;
    status_reason: string | null;
    unsubscribed_at: string | null;
    created_at: string;
};

export type EmailVariable = {
    key: string;
    value: string;
};

export type EmailStatus = 'draft' | 'scheduled' | 'sent';

export type SendMode = 'recurring' | 'once';

export type Email = {
    id: number | null;
    name: string;
    status: EmailStatus;
    send_mode: SendMode;
    subject: string;
    body: string;
    variables: EmailVariable[];
    send_days: number[];
    send_time: string;
    /** Local date and time, YYYY-MM-DDTHH:mm, for one-time emails. */
    send_at: string | null;
    next_send_at: string | null;
    last_sent_at?: string | null;
};

export type EmailSettings = {
    from_address: string;
    from_name: string;
    reply_to: string | null;
    variables: EmailVariable[] | null;
};

export type RunStatus =
    | 'scheduled'
    | 'sending'
    | 'paused'
    | 'completed'
    | 'cancelled';

export type EmailRun = {
    id: number;
    email_id: number | null;
    email?: { id: number; name: string } | null;
    scheduled_for: string;
    status: RunStatus;
    pause_reason: string | null;
    paused_at: string | null;
    subject: string;
    body: string;
    deliveries_count?: number;
    sent_count?: number;
    failed_count?: number;
};

export type DeliveryStatus =
    | 'pending'
    | 'sent'
    | 'bounced'
    | 'failed'
    | 'cancelled';

export type EmailDelivery = {
    id: number;
    scheduled_at: string;
    sent_at: string | null;
    status: DeliveryStatus;
    attempts: number;
    delivered_at: string | null;
    last_event: string | null;
    error: string | null;
    recipient: Pick<EmailRecipient, 'id' | 'email' | 'name' | 'status'> | null;
};
