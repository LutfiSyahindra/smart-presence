export interface BulkShiftOptions {
    month: string;
    employees: { id: number; nik: string | null; nama: string; unit_kerja_id: number | null; jabatan_id: number | null }[];
    units: { id: number; nama: string }[];
    positions: { id: number; nama: string; unit_kerja_id: number }[];
}
