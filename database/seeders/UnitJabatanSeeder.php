<?php

namespace Database\Seeders;

use App\Models\Jabatan;
use App\Models\UnitKerja;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitJabatanSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const UNIT_JABATAN = [
        'Direksi' => [
            'Direktur',
            'Wakil Direktur Umum dan Keuangan',
            'Wakil Direktur Medik dan Keperawatan',
        ],
        'Bidang Penunjang Medis' => [
            'Kepala Bidang Penunjang Medis',
        ],
        'Unit Gawat Darurat' => [
            'Kepala Unit Gawat Darurat',
            'Dokter Unit Gawat Darurat',
            'Staff Unit Gawat Darurat',
        ],
        'Bidang Pelayanan Medis' => [
            'Kepala Bidang Pelayanan Medis',
        ],
        'Bidang Keperawatan' => [
            'Kepala Bidang Keperawatan',
        ],
        'Unit Bedah dan Anastesi' => [
            'Kepala Unit Bedah dan Anastesi',
            'Staff Unit Bedah dan Anastesi',
            'Dokter Spesialis',
        ],
        'Unit Rawat Jalan' => [
            'Kepala Unit Rawat Jalan',
            'Staff Unit Rawat Jalan',
            'Dokter Spesialis',
        ],
        'Unit Rawat Inap Dewasa' => [
            'Kepala Unit Rawat Inap Dewasa',
            'Staff Unit Rawat Inap Musa',
            'Staff Unit Rawat Inap Ibrahim',
            'Kepala Seksi Keperawatan Rawat Jalan',
        ],
        'Unit Rawat Inap Anak & Bersalin' => [
            'Kepala Seksi Keperawatan Rawat Inap',
            'Staff Unit Rawat Inap Khadijah',
            'Staff Unit Kamar Bersalin',
            'Kepala Unit Rawat Inap Anak dan Bersalin',
        ],
        'Unit Farmasi' => [
            'Kepala Unit Instalasi Farmasi',
            'Staff Unit Farmasi',
        ],
        'Unit Gizi' => [
            'Kepala Unit Gizi',
            'Staff Unit Gizi',
        ],
        'Unit Rekam Medis' => [
            'Kepala Unit Rekam Medis',
            'Staff Unit Rekam Medis',
            'Staff Pendaftaran',
        ],
        'Unit Penunjang Diagnostik' => [
            'Koordinator Fisioterapi',
            'Staff Fisioterapi',
            'Koordinator Radiologi',
            'Staff Radiologi',
            'Staff Laboratorium',
            'Kepala Unit Penunjang Diagnostik',
            'Dokter Spesialis',
        ],
        'Bagian Administrasi dan SDI' => [
            'Kepala Bagian Administrasi dan SDI',
            'Staff Bagian Administrasi dan SDI',
            'Staff Binroh',
        ],
        'Unit Personalia dan Diklat' => [
            'Kepala Unit Personalia dan Diklat',
        ],
        'Bagian Umum dan Rumah Tangga' => [
            'Kepala Bagian Umum dan Rumah Tangga',
        ],
        'Unit Sanitasi dan Pemeliharaan' => [
            'Kepala Unit Sanitasi dan Pemeliharaan',
            'Koordinator IPSRS',
            'Staff Maintenance',
            'Staff Helper',
        ],
        'Unit Keamanan dan Kendaraan' => [
            'Kepala Unit Keamanan dan Kendaraan',
            'Staff Keamanan',
            'Staff Parkir',
            'Staff Driver',
        ],
        'Unit Laundry dan Sterilisasi' => [
            'Kepala Unit Laundry dan Sterilisasi',
            'Staff Unit Laundry',
            'Staff Unit Sterilisasi',
        ],
        'Bagian Keuangan' => [
            'Kepala Bagian Keuangan',
            'Staff Logistik Umum',
        ],
        'Unit Akunting dan Pajak' => [
            'Kepala Unit Akunting dan Pajak',
        ],
        'Unit Bendahara dan Kasir' => [
            'Kepala Unit Bendahara dan Kasir',
            'Koordinator Kasir',
            'Staff Kasir',
        ],
        'Unit Asuransi dan Casemix' => [
            'Kepala Unit Asuransi dan Casemix',
            'Staff Unit Asuransi dan Casemix',
        ],
        'Unit Pemasaran' => [
            'Kepala Unit Pemasaran',
            'Staff Unit Pemasaran',
            'Staff PIPP',
        ],
        'SIRS' => [
            'Kepala Unit SIRS',
            'Staff SIRS',
        ],
        'SPI' => [
            'Koordinator SPI',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $unitNumber = 1;
            $jabatanNumber = 1;

            foreach (self::UNIT_JABATAN as $namaUnit => $jabatans) {
                $unit = UnitKerja::query()->updateOrCreate(
                    ['nama' => $namaUnit],
                    [
                        'kode' => sprintf('UK-%03d', $unitNumber),
                        'aktif' => true,
                    ],
                );

                foreach ($jabatans as $namaJabatan) {
                    Jabatan::query()->updateOrCreate(
                        [
                            'unit_kerja_id' => $unit->id,
                            'nama' => $namaJabatan,
                        ],
                        [
                            'kode' => sprintf('JBT-%03d', $jabatanNumber),
                            'aktif' => true,
                        ],
                    );

                    $jabatanNumber++;
                }

                $unitNumber++;
            }
        });
    }
}
