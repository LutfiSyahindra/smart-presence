export const presensiLabels: Record<string, string> = {
    pending: 'Menunggu persetujuan',
    waiting: 'Menunggu giliran',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    skipped: 'Dilewati',
};

export const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();

export const actionLabel = (action: string) => (action === 'clock-in' ? 'Clock-in' : 'Clock-out');
