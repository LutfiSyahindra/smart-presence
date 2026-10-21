export interface HolidayPolicy {
    libur_sabtu: boolean;
    libur_minggu: boolean;
    libur_nasional: boolean;
    libur_cuti_bersama: boolean;
}

export const holidayOptions: { key: keyof HolidayPolicy; label: string; description: string }[] = [
    { key: 'libur_sabtu', label: 'Sabtu', description: 'Libur setiap hari Sabtu' },
    { key: 'libur_minggu', label: 'Minggu', description: 'Libur setiap hari Minggu' },
    { key: 'libur_nasional', label: 'Libur nasional', description: 'Mengikuti kalender libur nasional' },
    { key: 'libur_cuti_bersama', label: 'Cuti bersama', description: 'Mengikuti kalender cuti bersama' },
];

export const defaultHolidayPolicy = (): HolidayPolicy => ({
    libur_sabtu: false,
    libur_minggu: false,
    libur_nasional: false,
    libur_cuti_bersama: false,
});

export const holidayPolicyLabel = (policy: HolidayPolicy) =>
    holidayOptions
        .filter((option) => policy[option.key])
        .map((option) => option.label)
        .join(', ') || 'Tetap masuk setiap hari';

export interface HolidayRow {
    id: number;
    tanggal: string;
    nama: string;
    jenis: 'libur_nasional' | 'cuti_bersama';
    source: string | null;
    synced_at: string | null;
}

export interface HolidaySyncInfo {
    synced_at: string | null;
    provider_name: string;
    provider_url: string;
    references: { title: string; url: string }[];
    auto_sync: boolean;
}
