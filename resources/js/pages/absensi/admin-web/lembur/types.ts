import type { LemburBalance, LemburRow } from '../../absensi-mobile/lembur/types';

export type MasterLemburRow = Pick<
    LemburRow,
    | 'id'
    | 'name'
    | 'position'
    | 'unit'
    | 'date'
    | 'shift_name'
    | 'request_kind'
    | 'usage_type'
    | 'usage_start'
    | 'usage_end'
    | 'compensation'
    | 'duration_minutes'
    | 'status'
    | 'reason'
>;
export type MasterLemburDetail = MasterLemburRow &
    Pick<
        LemburRow,
        | 'shift_start'
        | 'shift_end'
        | 'before_start'
        | 'before_end'
        | 'after_start'
        | 'after_end'
        | 'before_break_minutes'
        | 'after_break_minutes'
        | 'created_at'
        | 'current_level'
        | 'manual_balance_minutes'
        | 'sources'
        | 'approvals'
    > & {
        attachments: { name: string; size: number; url: string }[];
        recorded_by: string | null;
        cancelled_by: string | null;
        cancellation_note: string | null;
        decided_at: string | null;
    };
export type EmployeeBalance = LemburBalance & { id: number; name: string; nik: string | null; unit: string | null };
export type LemburDetailPayload = {
    lembur: MasterLemburDetail;
    balance: LemburBalance | null;
    can: { cancel: boolean; decide: boolean };
    allowApprovedCancellation: boolean;
};
export type EmployeeOption = { value: number; label: string; description: string; has_account: boolean; shift_id: number | null };
export type LemburFilters = {
    search: string;
    start_date: string;
    end_date: string;
    status: string;
    request_kind: 'all' | 'overtime' | 'usage';
    compensation: string;
    unit: string;
    pegawai_id: number | string;
};
