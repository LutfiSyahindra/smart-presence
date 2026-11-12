<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use App\Repositories\Absensi\AdminWeb\MasterData\Pegawai\PegawaiRepositoryInterface;
use App\Services\Absensi\AdminWeb\Setting\User\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;
use Throwable;

class PegawaiService
{
    public function __construct(
        private readonly PegawaiRepositoryInterface $pegawais,
        private readonly PegawaiSpreadsheetService $spreadsheets,
        private readonly UserService $users,
    ) {}

    /** @param array{search?: string|null, status?: string|null, unit_kerja?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;
        $filters['status'] = in_array($filters['status'] ?? null, ['Tetap', 'Tidak Tetap'], true)
            ? $filters['status']
            : null;
        $filters['unit_kerja'] = filter_var($filters['unit_kerja'] ?? null, FILTER_VALIDATE_INT) ?: null;

        return $this->pegawais->paginate($filters)->through(fn (Pegawai $pegawai) => [
            'id' => $pegawai->id,
            'nik' => $pegawai->nik,
            'nama' => $pegawai->nama,
            'profesi' => $pegawai->profesi,
            'unit_kerja_id' => $pegawai->unit_kerja_id,
            'unit_kerja' => $pegawai->unitKerja?->nama,
            'jabatan_id' => $pegawai->jabatan_id,
            'jabatan' => $pegawai->jabatan?->nama,
            'jenis_kelamin' => $pegawai->jenis_kelamin,
            'status_pegawai' => $pegawai->status_pegawai,
            'tanggal_mulai_bekerja' => $pegawai->tanggal_mulai_bekerja?->format('Y-m-d'),
            'tanggal_mulai_bekerja_label' => $pegawai->tanggal_mulai_bekerja?->translatedFormat('d M Y'),
            'tanggal_lahir' => $pegawai->tanggal_lahir?->format('Y-m-d'),
            'tanggal_lahir_label' => $pegawai->tanggal_lahir?->translatedFormat('d M Y'),
            'usia' => $pegawai->tanggal_lahir?->age,
            'nomor_telepon' => $pegawai->nomor_telepon,
            'email' => $pegawai->email,
            'user_id' => $pegawai->user_id,
            'account_status' => match (true) {
                $pegawai->user?->email_verified_at !== null => 'active',
                $pegawai->user !== null => 'pending',
                default => 'none',
            },
            'created_at' => $pegawai->created_at?->format('d M Y'),
            'updated_at' => $pegawai->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, tetap: int, tidak_tetap: int, joined_this_year: int} */
    public function overview(): array
    {
        return $this->pegawais->overview();
    }

    /** @return array<int, array{id: int, nama: string, jabatans: array<int, array{id: int, nama: string}>}> */
    public function formOptions(): array
    {
        return UnitKerja::query()
            ->where('aktif', true)
            ->with(['jabatans' => fn ($query) => $query->where('aktif', true)->select('id', 'unit_kerja_id', 'nama')])
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn (UnitKerja $unit) => [
                'id' => (int) $unit->id,
                'nama' => $unit->nama,
                'jabatans' => $unit->jabatans->map(fn ($jabatan) => [
                    'id' => (int) $jabatan->id,
                    'nama' => $jabatan->nama,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function template(): Spreadsheet
    {
        return $this->spreadsheets->template();
    }

    /** @return list<array{id: int, nik: ?string, nama: string, unit_kerja: ?string}> */
    public function exportOptions(): array
    {
        return Pegawai::query()
            ->with('unitKerja:id,nama')
            ->orderBy('nama')->orderBy('id')
            ->get(['id', 'nik', 'nama', 'unit_kerja_id'])
            ->map(fn (Pegawai $pegawai) => [
                'id' => $pegawai->id,
                'nik' => $pegawai->nik,
                'nama' => $pegawai->nama,
                'unit_kerja' => $pegawai->unitKerja?->nama,
            ])->all();
    }

    /** @param list<int> $pegawaiIds */
    public function export(bool $all, array $pegawaiIds): Spreadsheet
    {
        $query = Pegawai::query()
            ->with(['unitKerja:id,nama', 'jabatan:id,nama'])
            ->when(! $all, fn ($query) => $query->whereIn('id', $pegawaiIds))
            ->orderBy('nama')->orderBy('id');

        if (! $query->exists()) {
            throw ValidationException::withMessages(['pegawai_ids' => 'Tidak ada data pegawai yang dapat diekspor.']);
        }

        return $this->spreadsheets->export($query->lazy(500));
    }

    /** @return array{total: int, created: int, updated: int} */
    public function import(UploadedFile $file, bool $canUpdate): array
    {
        $items = $this->resolveOrganization($this->spreadsheets->read($file));

        return DB::transaction(function () use ($items, $canUpdate): array {
            $existing = Pegawai::query()
                ->where(function ($query) use ($items) {
                    foreach (['nik', 'nomor_telepon', 'email'] as $field) {
                        $values = array_values(array_filter(array_column($items, $field), fn ($value) => $value !== null));
                        $query->orWhereIn($field, $values);
                    }
                })
                ->lockForUpdate()
                ->get();
            $byNik = $existing->whereNotNull('nik')->keyBy(fn (Pegawai $pegawai) => mb_strtolower($pegawai->nik));
            $byPhone = $existing->whereNotNull('nomor_telepon')->keyBy('nomor_telepon');
            $byEmail = $existing->whereNotNull('email')->keyBy(fn (Pegawai $pegawai) => mb_strtolower($pegawai->email));
            $matchedRows = [];
            $result = ['total' => count($items), 'created' => 0, 'updated' => 0];

            foreach ($items as $item) {
                $row = $item['source_row'];
                $nik = $item['nik'] ?? null;
                $matches = collect([
                    $nik !== null ? $byNik->get(mb_strtolower($nik)) : null,
                    $byPhone->get($item['nomor_telepon'] ?? ''),
                    $byEmail->get(mb_strtolower($item['email'] ?? '')),
                ])->filter()->unique('id');

                if ($matches->count() > 1) {
                    throw ValidationException::withMessages([
                        'file' => "Baris {$row}: NIK, nomor telepon, atau email mengarah ke pegawai yang berbeda. Periksa kembali identitas pegawai.",
                    ]);
                }

                $pegawai = $matches->first();
                if ($pegawai !== null && $nik !== null && $pegawai->nik !== null
                    && mb_strtolower($nik) !== mb_strtolower($pegawai->nik)) {
                    throw ValidationException::withMessages([
                        'file' => "Baris {$row}: nomor telepon atau email sudah digunakan pegawai dengan NIK berbeda.",
                    ]);
                }

                unset($item['source_row']);
                if ($pegawai === null) {
                    $this->pegawais->create($item);
                    $result['created']++;

                    continue;
                }

                if (! $canUpdate) {
                    throw new AuthorizationException('Anda tidak memiliki izin memperbarui data pegawai.');
                }

                if (isset($matchedRows[$pegawai->id])) {
                    throw ValidationException::withMessages([
                        'file' => "Baris {$row}: pegawai yang sama sudah tercantum pada baris {$matchedRows[$pegawai->id]}.",
                    ]);
                }

                $matchedRows[$pegawai->id] = $row;
                try {
                    $this->update($pegawai, $item);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        'file' => "Baris {$row}: ".collect($exception->errors())->flatten()->first(),
                    ]);
                }
                $result['updated']++;
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Pegawai
    {
        return DB::transaction(function () use ($data): Pegawai {
            $pegawai = $this->pegawais->create($data);

            return $pegawai->load(['unitKerja', 'jabatan', 'user']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Pegawai $pegawai, array $data): Pegawai
    {
        return DB::transaction(function () use ($pegawai, $data): Pegawai {
            if ($pegawai->user_id !== null
                && array_key_exists('email', $data)
                && $data['email'] !== $pegawai->email) {
                throw ValidationException::withMessages([
                    'email' => 'Email tidak dapat diubah selama akun terkait masih ada. Hapus akun terkait terlebih dahulu jika email harus diganti.',
                ]);
            }

            $pegawai = $this->pegawais->update($pegawai, $data);
            $pegawai->user?->update(['name' => $pegawai->nama]);

            return $pegawai->load(['unitKerja', 'jabatan', 'user']);
        });
    }

    public function delete(Pegawai $pegawai): void
    {
        if ($pegawai->user_id !== null) {
            throw ValidationException::withMessages([
                'pegawai' => 'Pegawai yang masih memiliki akun tidak dapat dihapus. Hapus akun terkait dari menu Pengguna terlebih dahulu.',
            ]);
        }

        $this->pegawais->delete($pegawai);
    }

    /**
     * Membuat akun baru atau mengirim ulang undangan untuk akun yang belum aktif.
     *
     * @return array{user: User, resent: bool}
     */
    public function inviteToUser(Pegawai $pegawai): array
    {
        $this->assertCanInvite($pegawai);
        $resent = $pegawai->user_id !== null;

        if ($resent) {
            $user = $pegawai->user;
            assert($user !== null);
        } else {
            $user = DB::transaction(function () use ($pegawai): User {
                Role::findOrCreate('pegawai', 'web');

                $user = User::query()->create([
                    'name' => $pegawai->nama,
                    'email' => $pegawai->email,
                    'password' => Str::random(64),
                ]);
                $user->assignRole('pegawai');

                $pegawai->forceFill(['user_id' => $user->id])->save();

                return $user;
            });
        }

        try {
            $token = Password::broker()->createToken($user);
            $user->notify(new EmployeeAccountInvitation($token));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'pegawai' => $resent
                    ? 'Email undangan gagal dikirim ulang. Periksa konfigurasi email lalu coba kembali.'
                    : 'Akun berhasil dibuat, tetapi email undangan gagal dikirim. Periksa konfigurasi email lalu gunakan Kirim ulang.',
            ]);
        }

        return ['user' => $user, 'resent' => $resent];
    }

    public function cancelUserInvitation(Pegawai $pegawai, User $actor): void
    {
        DB::transaction(function () use ($pegawai, $actor): void {
            $pegawai = Pegawai::query()->lockForUpdate()->findOrFail($pegawai->id);
            $user = $pegawai->user()->lockForUpdate()->first();

            if ($user === null || $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'pegawai' => 'Hanya pegawai yang sedang menunggu aktivasi yang dapat dibatalkan aktivasinya.',
                ]);
            }

            try {
                $this->users->delete($user, $actor);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'pegawai' => collect($exception->errors())->flatten()->first(),
                ]);
            }

            Password::broker()->deleteToken($user);
        });
    }

    /**
     * Membuat akun dan mengirim email aktivasi untuk beberapa pegawai sekaligus.
     *
     * Seluruh pilihan divalidasi sebelum akun pertama dibuat agar kesalahan data
     * tidak menghasilkan batch yang hanya terproses sebagian.
     *
     * @param  list<int>  $pegawaiIds
     * @return array{total: int, created: int, resent: int}
     */
    public function inviteManyToUsers(array $pegawaiIds): array
    {
        $pegawais = Pegawai::query()
            ->with('user')
            ->whereKey($pegawaiIds)
            ->get()
            ->keyBy('id');

        if ($pegawais->count() !== count($pegawaiIds)) {
            throw ValidationException::withMessages([
                'pegawai_ids' => 'Salah satu pegawai yang dipilih tidak ditemukan. Muat ulang halaman lalu coba kembali.',
            ]);
        }

        foreach ($pegawaiIds as $pegawaiId) {
            $pegawai = $pegawais->get($pegawaiId);
            assert($pegawai instanceof Pegawai);

            try {
                $this->assertCanInvite($pegawai);
            } catch (ValidationException $exception) {
                $reason = collect($exception->errors())->flatten()->first() ?? 'Data pegawai tidak dapat diproses.';

                throw ValidationException::withMessages([
                    'pegawai_ids' => "Undangan untuk {$pegawai->nama} tidak dapat diproses: {$reason}",
                ]);
            }
        }

        $created = 0;
        $resent = 0;

        foreach ($pegawaiIds as $pegawaiId) {
            $result = $this->inviteToUser($pegawais->get($pegawaiId));
            $result['resent'] ? $resent++ : $created++;
        }

        return [
            'total' => $created + $resent,
            'created' => $created,
            'resent' => $resent,
        ];
    }

    private function assertCanInvite(Pegawai $pegawai): void
    {
        if (blank($pegawai->email)) {
            throw ValidationException::withMessages([
                'pegawai' => 'Isi email pegawai terlebih dahulu sebelum membuat akun.',
            ]);
        }

        if ($pegawai->user_id !== null) {
            $user = $pegawai->relationLoaded('user') ? $pegawai->user : $pegawai->user()->first();

            if ($user === null) {
                throw ValidationException::withMessages([
                    'pegawai' => 'Akun terkait tidak ditemukan. Muat ulang halaman lalu coba kembali.',
                ]);
            }

            if ($user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'pegawai' => 'Akun pegawai sudah aktif dan emailnya telah terverifikasi.',
                ]);
            }

            return;
        }

        if (User::query()->where('email', $pegawai->email)->exists()) {
            throw ValidationException::withMessages([
                'pegawai' => 'Email pegawai sudah digunakan oleh akun lain.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function resolveOrganization(array $items): array
    {
        if (collect($items)->every(fn (array $item) => blank($item['unit_kerja'] ?? null))) {
            return array_map(function (array $item): array {
                unset($item['unit_kerja'], $item['jabatan']);

                return $item;
            }, $items);
        }

        $units = UnitKerja::query()
            ->with('jabatans:id,unit_kerja_id,nama')
            ->get()
            ->keyBy(fn (UnitKerja $unit) => str($unit->nama)->lower()->squish()->toString());
        $errors = [];

        foreach ($items as &$item) {
            $unitKey = str((string) ($item['unit_kerja'] ?? ''))->lower()->squish()->toString();
            $jabatanKey = str((string) ($item['jabatan'] ?? ''))->lower()->squish()->toString();
            $unit = $units->get($unitKey);

            if ($unit === null) {
                $errors[] = "Baris {$item['source_row']}: unit kerja {$item['unit_kerja']} belum tersedia di master.";

                continue;
            }

            $jabatan = $unit->jabatans->first(
                fn ($jabatan) => str($jabatan->nama)->lower()->squish()->toString() === $jabatanKey,
            );

            if ($jabatan === null) {
                $errors[] = "Baris {$item['source_row']}: jabatan {$item['jabatan']} belum tersedia pada unit {$unit->nama}.";

                continue;
            }

            $item['unit_kerja_id'] = $unit->id;
            $item['jabatan_id'] = $jabatan->id;
            unset($item['unit_kerja'], $item['jabatan']);
        }
        unset($item);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => implode(' ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' Periksa juga baris lainnya.' : ''),
            ]);
        }

        return $items;
    }
}
