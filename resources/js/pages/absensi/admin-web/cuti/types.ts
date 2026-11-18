export interface LeaveOption {
    id: number;
    nama: string;
    is_tahunan: boolean;
    tanpa_kuota: boolean;
    tahun: number;
    jumlah_hari: number;
    sisa: number;
}

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
    can_cancel?: boolean;
    approvals: { level: number; name: string; status: string; note: string | null; decided_at: string | null }[];
}
