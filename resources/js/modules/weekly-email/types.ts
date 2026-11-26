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

export type WeeklyEmailSettings = {
    enabled: boolean;
    subject: string;
    body: string;
    zoom_link: string | null;
    from_address: string;
    from_name: string;
    reply_to: string | null;
    send_day: number;
    send_time: string;
};

export type RunStatus =
    | 'scheduled'
    | 'sending'
    | 'paused'
    | 'completed'
    | 'cancelled';

export type EmailRun = {
    id: number;
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
    error: string | null;
    recipient: Pick<EmailRecipient, 'id' | 'email' | 'name' | 'status'> | null;
};
