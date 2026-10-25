import type { CutiRow } from '../cuti/types';

export type ApprovalRequestType = 'all' | 'cuti' | 'lembur' | 'perubahan-shift';

export interface ApprovalRequestRow extends CutiRow {
    request_type?: 'cuti' | 'lembur' | 'perubahan-shift';
    original_shift?: ShiftSnapshot | null;
    requested_shift?: ShiftSnapshot | null;
    duration_minutes?: number | null;
    shift_name?: string | null;
    request_kind?: 'overtime' | 'usage' | null;
    usage_type?: 'late_arrival' | 'early_departure' | null;
}

export type ShiftSnapshot = { id: number; name: string; start_time: string; end_time: string; next_day: boolean };
export const requestCode = (request: ApprovalRequestRow) =>
    `${request.request_type === 'perubahan-shift' ? 'SHIFT' : request.request_type === 'lembur' ? 'LEMBUR' : 'CUTI'}-${request.id}`;
export const duration = (minutes: number) => `${Math.floor(minutes / 60)}j ${minutes % 60}m`;
