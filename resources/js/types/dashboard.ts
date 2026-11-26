export type DashboardTone = 'success' | 'warning' | 'danger' | 'muted';

export type DashboardStatus = {
    label: string;
    tone: DashboardTone;
};

export type DashboardAttentionItem = {
    title: string;
    detail: string | null;
    href: string;
    tone: 'warning' | 'danger';
};

export type DashboardActivityItem = {
    at: string;
    title: string;
    detail: string | null;
    href: string;
};

export type DashboardCard = {
    key: string;
    name: string;
    icon: string;
    href: string;
    headline: {
        title: string;
        detail: string | null;
        status: DashboardStatus | null;
    };
    stats: Array<{ label: string; value: string; tone?: DashboardTone }>;
    attention: DashboardAttentionItem[];
    activity: DashboardActivityItem[];
    upcoming?: {
        title: string;
        items: Array<{
            title: string;
            detail: string | null;
            href: string;
            status: DashboardStatus | null;
        }>;
    };
};
