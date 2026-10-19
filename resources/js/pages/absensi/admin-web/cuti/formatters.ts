export const labels: Record<string, string> = {
    pending: 'Menunggu',
    waiting: 'Belum mendapat giliran',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    skipped: 'Tidak dilanjutkan',
};

export const date = (value: string) =>
    new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(
        new Date(`${value}T00:00:00+07:00`),
    );

export const days = (start: string, end: string) => {
    if (!start || !end) return 0;
    return Math.max(0, Math.round((Date.parse(end) - Date.parse(start)) / 86400000) + 1);
};
