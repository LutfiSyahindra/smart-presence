<?php

namespace App\Services\Absensi\AdminWeb\ApprovalLine;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalLineService
{
    public function approverUsers(): Collection
    {
        return User::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'email']);
    }

    public function save(array $data, ?ApprovalLine $line = null): ApprovalLine
    {
        return DB::transaction(function () use ($data, $line) {
            $type = $data['type'] ?? $line?->type ?? ApprovalLine::TYPE_CUTI;
            if (! in_array($type, ApprovalLine::types(), true) || ($line && $line->type !== $type)) {
                throw ValidationException::withMessages(['type' => 'Kategori approval line tidak valid atau berbeda dari alur yang sedang diubah.']);
            }

            // Lock positions in a stable order, including those removed by an edit.
            $positionIds = collect($data['jabatan_ids']);
            $lockedIds = $positionIds->merge($line?->jabatans()->pluck('jabatans.id') ?? [])->unique()->sort()->values();
            $positions = Jabatan::query()->with('unitKerja')->whereKey($lockedIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($data['jabatan_ids'] as $index => $positionId) {
                $jabatan = $positions->firstWhere('id', $positionId);
                if (! $jabatan?->aktif || ! $jabatan->unitKerja?->aktif) {
                    throw ValidationException::withMessages(["jabatan_ids.{$index}" => 'Unit dan jabatan harus aktif dan tersedia.']);
                }
            }

            if (ApprovalLine::query()->whereHas('jabatans', fn (Builder $query) => $query->whereIn('jabatans.id', $positionIds))->where('type', $type)
                ->when($line, fn (Builder $query) => $query->whereKeyNot($line->id))->exists()) {
                throw ValidationException::withMessages(['jabatan_ids' => "Salah satu jabatan sudah memiliki approval line {$type}. Silakan ubah alur tersebut."]);
            }

            if ($line) {
                $line = ApprovalLine::query()->lockForUpdate()->findOrFail($line->id);
                $line->steps()->delete();
                $line->touch();
            } else {
                $line = ApprovalLine::query()->create(['type' => $type]);
            }

            $line->jabatans()->sync($positionIds->mapWithKeys(fn ($id) => [$id => ['type' => $type]])->all());

            foreach ($data['approver_ids'] as $index => $userId) {
                $line->steps()->create(['user_id' => $userId, 'level' => $index + 1]);
            }

            return $line;
        });
    }

    public function delete(ApprovalLine $line): void
    {
        DB::transaction(function () use ($line) {
            ApprovalLine::query()->lockForUpdate()->findOrFail($line->id)->delete();
        });
    }
}
