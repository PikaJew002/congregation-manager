export type RecipientStatus = 'subscribed' | 'unsubscribed';

export type EmailRecipient = {
    id: number;
    email: string;
    name: string | null;
    status: RecipientStatus;
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
    window_minutes: number;
};

export type RunStatus = 'scheduled' | 'sending' | 'completed' | 'cancelled';

export type EmailRun = {
    id: number;
    scheduled_for: string;
    status: RunStatus;
    subject: string;
    body: string;
    deliveries_count?: number;
    sent_count?: number;
    failed_count?: number;
};

export type DeliveryStatus = 'pending' | 'sent' | 'failed' | 'cancelled';

export type EmailDelivery = {
    id: number;
    scheduled_at: string;
    sent_at: string | null;
    status: DeliveryStatus;
    error: string | null;
    recipient: Pick<EmailRecipient, 'id' | 'email' | 'name'> | null;
};
