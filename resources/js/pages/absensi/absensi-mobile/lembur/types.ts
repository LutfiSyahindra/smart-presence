export type LemburStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type LemburAttachment = { name: string; size: number; url: string; preview_url: string | null };
export type LemburRow = {
    request_kind: 'overtime' | 'usage';
    usage_type: 'late_arrival' | 'early_departure' | null;
    usage_start: string | null;
    usage_end: string | null;
    sources: { id: number; date: string; minutes: number }[];
    id: number;
    date: string;
    name: string;
    position: string;
    unit: string;
    shift_name: string;
    shift_start: string;
    shift_end: string;
    start_time: string;
    end_time: string;
    before_start: string | null;
    before_end: string | null;
    after_start: string | null;
    after_end: string | null;
    before_minutes: number;
    before_break_minutes: number;
    after_minutes: number;
    after_break_minutes: number;
    duration_minutes: number;
    compensation: 'pay' | 'time_off';
    reason: string;
    status: LemburStatus;
    current_level: number | null;
    created_at: string;
    can_cancel: boolean;
    can_decide: boolean;
    attachments: LemburAttachment[];
    approvals: { level: number; name: string; status: string; note: string | null; decided_at: string | null }[];
};

export type LemburBalance = { earned_minutes: number; reserved_minutes: number; used_minutes: number; available_minutes: number };

export const lemburStatuses: { value: '' | LemburStatus; label: string; description: string }[] = [
    { value: '', label: 'Semua', description: 'Seluruh pengajuan lembur' },
    { value: 'pending', label: 'Menunggu', description: 'Dalam proses persetujuan' },
    { value: 'approved', label: 'Disetujui', description: 'Sudah mendapat persetujuan' },
    { value: 'rejected', label: 'Ditolak', description: 'Pengajuan yang tidak disetujui' },
    { value: 'cancelled', label: 'Dibatalkan', description: 'Pengajuan yang dibatalkan' },
];
export function lemburDuration(minutes: number) {
    return `${Math.floor(minutes / 60)}j ${minutes % 60}m`;
}
