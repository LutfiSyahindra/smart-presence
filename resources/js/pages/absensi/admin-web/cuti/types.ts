export interface CutiRow {
    id: number;
    nama_pegawai: string;
    jabatan: string;
    unit: string;
    tanggal_mulai: string;
    tanggal_selesai: string;
    alasan: string;
    nama_cuti: string | null;
    jumlah_hari: number | null;
    status: string;
    current_level: number | null;
    created_at: string;
    can_decide: boolean;
    approvals: { level: number; name: string; status: string; note: string | null; decided_at: string | null }[];
}
