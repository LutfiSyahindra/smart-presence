<?php

namespace App\Support;

use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Models\PerubahanShiftRequest;
use App\Models\PresensiRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;

class NotificationFilters
{
    public static function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when(fn ($input) => ! empty($input->date_from), 'after_or_equal:date_from')],
            'unit' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'employee_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public static function values(array $data): array
    {
        return array_map(fn ($value) => trim($value ?? ''), array_replace(array_fill_keys(array_keys(self::rules()), ''), array_intersect_key($data, self::rules())));
    }

    public static function apply(Builder|Relation $query, array $filters, string $start, ?string $end = null): void
    {
        self::dates($query, $filters, $start, $end ?? $start);
        self::employee($query, $filters);
    }

    private static function dates(Builder|Relation $query, array $filters, string $start, string $end): void
    {
        if ($filters['date_from'] !== '') {
            $query->whereDate($end, '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate($start, '<=', $filters['date_to']);
        }
    }

    private static function employee(Builder|Relation $query, array $filters): void
    {
        foreach (['unit' => 'nama_unit', 'jabatan' => 'nama_jabatan'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['employee_name'] !== '') {
            $query->where('nama_pegawai', 'like', '%'.$filters['employee_name'].'%');
        }
    }

    /** Options come only from requests already visible to this user. */
    public static function options(array $queries): array
    {
        $options = [];
        foreach (['units' => 'nama_unit', 'positions' => 'nama_jabatan'] as $key => $column) {
            $options[$key] = collect($queries)->flatMap(fn ($query) => (clone $query)->reorder()->distinct()->pluck($column))
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')->unique()->sort()->values()->all();
        }

        return $options;
    }

    private static function notificationRequests(Builder|Relation $notifications): array
    {
        $queries = [];
        foreach (['cuti' => CutiRequest::class, 'lembur' => LemburRequest::class,
            'perubahan-shift' => PerubahanShiftRequest::class, 'presensi' => PresensiRequest::class] as $type => $model) {
            $queries[$type] = $model::query()->whereIn('id', (clone $notifications)->reorder()->where('data->request_type', $type)->select('data->request_id'));
        }

        return $queries;
    }

    public static function notificationOptions(Builder|Relation $notifications): array
    {
        return self::options(self::notificationRequests($notifications));
    }

    public static function applyNotifications(Builder|Relation $notifications, array $filters): void
    {
        $requests = self::notificationRequests($notifications);
        self::dates($notifications, $filters, 'notifications.created_at', 'notifications.created_at');
        if ($filters['unit'] === '' && $filters['jabatan'] === '' && $filters['employee_name'] === '') {
            return;
        }
        $notifications->where(function (Builder $query) use ($requests, $filters) {
            foreach ($requests as $type => $requestsQuery) {
                self::employee($requestsQuery, $filters);
                $query->orWhere(fn (Builder $query) => $query->where('data->request_type', $type)
                    ->whereIn('data->request_id', $requestsQuery->select('id')));
            }
        });
    }
}
