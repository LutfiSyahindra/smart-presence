import type { LucideIcon } from 'lucide-vue-next';
import type { AdminNotificationCounts } from './notifications';

export interface Auth {
    user: User;
    roles: string[];
    permissions: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData extends Record<string, unknown> {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    adminNotificationCounts: AdminNotificationCounts | null;
    portal: {
        active: 'presensi' | 'admin';
        can_access_admin: boolean;
        can_access_cuti: boolean;
        admin_home_url: string;
    };
    flash: {
        success?: string;
        error?: string;
    };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
