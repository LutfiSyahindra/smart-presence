<?php

namespace App\Services\Mobile;

use App\Models\MobileAppRelease;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppReleaseService
{
    public function __construct(private readonly ApkInspector $inspector) {}

    public function active(): ?MobileAppRelease
    {
        $id = DB::table('mobile_app_distribution')->where('id', 1)->value('release_id');

        return $id ? MobileAppRelease::find($id) : null;
    }

    public function installation(): array
    {
        $release = $this->active();
        if ($release && Storage::disk('local')->exists($release->path)) {
            return ['androidApkUrl' => $this->downloadUrl($release), 'androidRelease' => $this->metadata($release)];
        }
        // Once managed releases are used, withdrawing a release must not expose an old APK.
        $legacy = $release === null && (int) DB::table('mobile_app_distribution')->where('id', 1)->value('highest_version_code') === 0;

        return [
            'androidApkUrl' => $legacy ? (config('mobile.android_apk_url')
                ?: (is_file(public_path('downloads/arsy-presensi.apk')) ? asset('downloads/arsy-presensi.apk') : null)) : null,
            'androidRelease' => null,
        ];
    }

    public function upload(UploadedFile $file, array $data, User $user): MobileAppRelease
    {
        $metadata = $this->inspector->inspect($file->getRealPath());
        if (MobileAppRelease::where('version_code', $metadata['version_code'])->exists()) {
            throw ValidationException::withMessages(['apk' => 'Versi APK ini sudah tersimpan. Naikkan versionCode sebelum membuat rilis baru.']);
        }
        $this->validatePolicy($data, $metadata['version_code']);
        $data = $this->normalizePolicy($data);
        $path = 'mobile-releases/'.Str::uuid().'.apk';
        $stored = Storage::disk('local')->putFileAs('mobile-releases', $file, basename($path));
        if (! $stored) {
            throw ValidationException::withMessages(['apk' => 'Penyimpanan APK gagal. Periksa ruang dan izin penyimpanan server.']);
        }

        try {
            return MobileAppRelease::create([
                ...$metadata, ...$data, 'path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath()),
                'size_bytes' => $file->getSize(), 'uploaded_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            Storage::disk('local')->delete($path);
            if (MobileAppRelease::where('version_code', $metadata['version_code'])->exists()) {
                throw ValidationException::withMessages(['apk' => 'Versi APK ini sudah tersimpan. Naikkan versionCode sebelum membuat rilis baru.']);
            }
            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function update(MobileAppRelease $release, array $data): void
    {
        DB::transaction(function () use ($release, $data) {
            $channel = DB::table('mobile_app_distribution')->where('id', 1)->lockForUpdate()->first();
            $release->refresh();
            if ($release->published_at && (int) $channel->release_id !== $release->id) {
                throw ValidationException::withMessages(['release' => 'Rilis yang sudah diarsipkan tidak dapat diubah.']);
            }
            $this->validatePolicy($data, $release->version_code);
            $release->update($this->normalizePolicy($data));
        });
    }

    public function discard(MobileAppRelease $release): void
    {
        DB::transaction(function () use ($release) {
            DB::table('mobile_app_distribution')->where('id', 1)->lockForUpdate()->first();
            $release->refresh();
            if ($release->published_at) {
                throw ValidationException::withMessages(['release' => 'Rilis yang pernah diterbitkan harus tetap disimpan sebagai riwayat.']);
            }
            $release->delete();
        });
        Storage::disk('local')->delete($release->path);
    }

    public function publish(MobileAppRelease $release, User $user): void
    {
        DB::transaction(function () use ($release, $user) {
            $channel = DB::table('mobile_app_distribution')->where('id', 1)->lockForUpdate()->first();
            $release->refresh();
            if ($release->published_at || $release->version_code <= $channel->highest_version_code) {
                throw ValidationException::withMessages(['release' => 'Rilis harus memiliki versionCode lebih tinggi dari semua rilis yang pernah diterbitkan.']);
            }
            $path = Storage::disk('local')->path($release->path);
            if (! is_file($path) || ! hash_equals($release->sha256, hash_file('sha256', $path))) {
                throw ValidationException::withMessages(['release' => 'File APK hilang atau berubah. Unggah ulang APK sebelum diterbitkan.']);
            }
            // Recheck the cryptographic signature and trust configuration at publication.
            $metadata = $this->inspector->inspect($path);
            if ($metadata['version_code'] !== $release->version_code) {
                throw ValidationException::withMessages(['release' => 'Identitas versi APK tidak sesuai.']);
            }
            $release->update(['published_at' => now(), 'published_by' => $user->id]);
            DB::table('mobile_app_distribution')->where('id', 1)->update([
                'release_id' => $release->id, 'highest_version_code' => $release->version_code,
            ]);
        });
    }

    public function withdraw(MobileAppRelease $release, User $user): void
    {
        DB::transaction(function () use ($release, $user) {
            $channel = DB::table('mobile_app_distribution')->where('id', 1)->lockForUpdate()->first();
            if ((int) $channel->release_id !== $release->id) {
                throw ValidationException::withMessages(['release' => 'Hanya rilis aktif yang dapat ditarik.']);
            }
            $release->update(['withdrawn_at' => now(), 'withdrawn_by' => $user->id]);
            DB::table('mobile_app_distribution')->where('id', 1)->update(['release_id' => null]);
        });
    }

    public function check(int $versionCode, ?int $sdk): array
    {
        $release = $this->active();
        if ($release && ! Storage::disk('local')->exists($release->path)) {
            $release = null;
        }
        $compatible = $release !== null && ($sdk === null || $sdk >= $release->min_sdk);
        $available = $release !== null && $versionCode < $release->version_code;

        return [
            'release' => $release ? $this->metadata($release) : null,
            'update_available' => $available,
            'compatible' => $compatible,
            'update_required' => $available && $compatible && $versionCode > 0 && $versionCode < $release->minimum_version_code
                && ($release->required_at === null || $release->required_at->isPast()),
        ];
    }

    public function metadata(MobileAppRelease $release): array
    {
        return [
            'id' => $release->id, 'version_code' => $release->version_code, 'version_name' => $release->version_name,
            'min_sdk' => $release->min_sdk, 'size_bytes' => $release->size_bytes, 'sha256' => $release->sha256,
            'release_notes' => $release->release_notes, 'minimum_version_code' => $release->minimum_version_code,
            'required_at' => $release->required_at?->toIso8601String(),
            'published_at' => $release->published_at?->toIso8601String(), 'download_url' => $this->downloadUrl($release),
        ];
    }

    private function downloadUrl(MobileAppRelease $release): string
    {
        return route('app.android.download', ['release' => $release->id], absolute: false);
    }

    private function validatePolicy(array $data, int $versionCode): void
    {
        if ($data['minimum_version_code'] > $versionCode) {
            throw ValidationException::withMessages(['minimum_version_code' => 'Versi minimum tidak boleh melebihi versionCode APK.']);
        }
    }

    private function normalizePolicy(array $data): array
    {
        $data['required_at'] = $data['minimum_version_code'] > 1 && ! empty($data['required_at'])
            ? Carbon::parse($data['required_at'])->timezone(config('app.timezone'))->toDateTimeString() : null;

        return $data;
    }
}
