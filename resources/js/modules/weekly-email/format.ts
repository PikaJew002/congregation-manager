export { formatDateTime } from '@/lib/format';

export const weekdays = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

export function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'sent':
        case 'completed':
        case 'subscribed':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'cancelled':
        case 'unsubscribed':
            return 'outline';
        default:
            return 'secondary';
    }
}
