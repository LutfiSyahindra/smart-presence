export interface AdminNotification {
    id: string;
    title: string;
    message: string;
    event: string;
    read_at: string | null;
    created_at: string;
    url: string;
}

export interface NotificationFeed {
    items: AdminNotification[];
    unread_count: number;
    push_public_key: string | null;
}
