import type { CutiRow } from '../cuti/types';

export interface ApprovalRequestRow extends CutiRow {
    request_type?: 'cuti' | 'lembur';
    duration_minutes?: number | null;
    shift_name?: string | null;
    request_kind?: 'overtime' | 'usage' | null;
    usage_type?: 'late_arrival' | 'early_departure' | null;
}

export const requestCode = (request: ApprovalRequestRow) => `${request.request_type === 'lembur' ? 'LEMBUR' : 'CUTI'}-${request.id}`;
export const duration = (minutes: number) => `${Math.floor(minutes / 60)}j ${minutes % 60}m`;
