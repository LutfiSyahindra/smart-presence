<?php

namespace App\Models;

use App\Services\Absensi\ConfigurationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Presensi extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'pegawai_id',
        'shift_id',
        'tanggal_presensi',
        'clock_in_at',
        'clock_out_at',
        'clock_in_approval_status',
        'clock_out_approval_status',
        'clock_in_location_id',
        'clock_out_location_id',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_accuracy',
        'clock_in_location_sample_count',
        'clock_in_location_verified_at',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_accuracy',
        'clock_out_location_sample_count',
        'clock_out_location_verified_at',
        'clock_in_address',
        'clock_out_address',
        'clock_in_photo_path',
        'face_verified_at',
        'face_similarity',
        'face_verification_provider',
        'clock_out_photo_path',
        'clock_out_face_verified_at',
        'clock_out_face_similarity',
        'clock_out_face_verification_provider',
        'is_day_off',
        'overtime_minutes',
        'notes',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_presensi' => 'date',
            'clock_in_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'face_similarity' => 'float',
            'clock_out_at' => 'datetime',
            'clock_out_face_verified_at' => 'datetime',
            'clock_out_face_similarity' => 'float',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_in_accuracy' => 'float',
            'clock_in_location_sample_count' => 'integer',
            'clock_in_location_verified_at' => 'datetime',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'clock_out_accuracy' => 'float',
            'clock_out_location_sample_count' => 'integer',
            'clock_out_location_verified_at' => 'datetime',
            'is_day_off' => 'boolean',
            'overtime_minutes' => 'integer',
        ];
    }

    public function setTanggalPresensiAttribute($value): void
    {
        $this->attributes['tanggal_presensi'] = $value === null ? null : Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<Pegawai, $this> */
    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<OfficeLocation, $this> */
    public function clockInLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'clock_in_location_id');
    }

    /** @return BelongsTo<OfficeLocation, $this> */
    public function clockOutLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'clock_out_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvalState(): ?string
    {
        $states = [$this->clock_in_approval_status, $this->clock_out_approval_status];

        return in_array('rejected', $states, true) ? 'rejected' : (in_array('pending', $states, true) ? 'pending' : null);
    }

    /** Pending/rejected evidence is visible but is not an accepted attendance record. */
    public function scopeAccepted(Builder $query): Builder
    {
        return self::constrainAccepted($query);
    }

    public static function constrainAccepted(Builder $query): Builder
    {
        foreach (['clock_in_approval_status', 'clock_out_approval_status'] as $column) {
            $query->where(fn ($query) => $query->whereNull('presensis.'.$column)->orWhere('presensis.'.$column, 'approved'));
        }

        return $query;
    }

    public function lateMinutes(): int
    {
        if ($this->is_day_off || ! $this->clock_in_at || ! $this->shift || ! $this->tanggal_presensi) {
            return 0;
        }

        $scheduledClockIn = Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->tanggal_presensi->format('Y-m-d').' '.substr((string) $this->shift->jam_masuk, 0, 5),
        );

        $scheduledClockOut = $this->tanggal_presensi->copy()->setTimeFromTimeString($this->shift->jam_keluar);
        if ($scheduledClockOut->lte($scheduledClockIn)) {
            $scheduledClockOut->addDay();
        }
        $scheduledClockIn->addMinutes($this->lemburAllowance('late_arrival', $scheduledClockIn, $scheduledClockOut));
        $scheduledClockIn->addMinutes(app(ConfigurationService::class)->current()->clock_in_tolerance_minutes);

        return $this->clock_in_at->gt($scheduledClockIn)
            ? (int) $scheduledClockIn->diffInMinutes($this->clock_in_at)
            : 0;
    }

    public function earlyDepartureMinutes(): int
    {
        if ($this->is_day_off || ! $this->clock_out_at || ! $this->shift || ! $this->tanggal_presensi) {
            return 0;
        }

        $scheduledClockIn = Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->tanggal_presensi->format('Y-m-d').' '.substr((string) $this->shift->jam_masuk, 0, 5),
        );
        $scheduledClockOut = Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->tanggal_presensi->format('Y-m-d').' '.substr((string) $this->shift->jam_keluar, 0, 5),
        );

        if ($scheduledClockOut->lte($scheduledClockIn)) {
            $scheduledClockOut->addDay();
        }

        $scheduledClockOut->subMinutes($this->lemburAllowance('early_departure', $scheduledClockIn, $scheduledClockOut));

        return $this->clock_out_at->lt($scheduledClockOut)
            ? (int) $this->clock_out_at->diffInMinutes($scheduledClockOut)
            : 0;
    }

    public function approvedLemburUsage(): Collection
    {
        if (! $this->pegawai_id || ! $this->shift_id || ! $this->tanggal_presensi) {
            return collect();
        }
        if ($this->relationLoaded('approvedLemburUsage')) {
            return $this->getRelation('approvedLemburUsage');
        }

        return LemburRequest::query()->where('pegawai_id', $this->pegawai_id)->where('shift_id', $this->shift_id)
            ->whereDate('tanggal_lembur', $this->tanggal_presensi)->where('request_kind', 'usage')->where('status', 'approved')->get();
    }

    public function lemburAllowance(string $type, Carbon $start, Carbon $end): int
    {
        return (int) $this->approvedLemburUsage()->filter(fn ($usage) => $usage->usage_type === $type
            && (int) $usage->shift_id === (int) $this->shift_id
            && $usage->shift_start_at->equalTo($start) && $usage->shift_end_at->equalTo($end))->sum('duration_minutes');
    }

    /** Keep the SQL filters and totals consistent with the approved arrival boundary. */
    public static function constrainArrivalStatus(Builder $query, bool $late): Builder
    {
        $tolerance = app(ConfigurationService::class)->current()->clock_in_tolerance_minutes;

        return self::constrainAccepted($query)->where('presensis.is_day_off', false)->whereNotNull('presensis.clock_in_at')
            ->whereExists(function ($shift) use ($query, $late, $tolerance) {
                $allowance = "(select coalesce(sum(arrival_usage.duration_minutes), 0) from lembur_requests as arrival_usage
                    where arrival_usage.pegawai_id = presensis.pegawai_id and arrival_usage.shift_id = presensis.shift_id
                    and arrival_usage.tanggal_lembur = presensis.tanggal_presensi and arrival_usage.request_kind = 'usage'
                    and arrival_usage.usage_type = 'late_arrival' and arrival_usage.status = 'approved'
                    and substr(time(arrival_usage.shift_start_at), 1, 5) = substr(shifts.jam_masuk, 1, 5)
                    and substr(time(arrival_usage.shift_end_at), 1, 5) = substr(shifts.jam_keluar, 1, 5))";
                // lateMinutes() reports whole minutes. Both filters use that same boundary,
                // including allowances that cross midnight and the global tolerance.
                $boundary = $query->getConnection()->getDriverName() === 'sqlite'
                    ? "datetime(presensis.tanggal_presensi || ' ' || shifts.jam_masuk, '+' || (? + $allowance + 1) || ' minutes')"
                    : "timestampadd(MINUTE, (? + $allowance + 1), timestamp(presensis.tanggal_presensi, shifts.jam_masuk))";
                $shift->selectRaw('1')->from('shifts')->whereColumn('shifts.id', 'presensis.shift_id')
                    ->whereRaw('presensis.clock_in_at '.($late ? '>=' : '<').' '.$boundary, [$tolerance]);
            });
    }
}
