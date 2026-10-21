export interface AdminNotification {
    id: string;
    title: string;
    message: string;
    event: string;
    read_at: string | null;
    created_at: string;
    url: string;
}

export interface AdminNotificationCounts {
    unread_count: number;
    pending_approval_count: number;
}

export interface NotificationFeed extends AdminNotificationCounts {
    items: AdminNotification[];
    push_public_key: string | null;
}
