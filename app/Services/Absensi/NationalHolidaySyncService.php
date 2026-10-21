<?php

namespace App\Services\Absensi;

use App\Exceptions\HolidayCalendarException;
use App\Models\HariLibur;
use App\Models\HolidayCalendarSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NationalHolidaySyncService
{
    public const SOURCE = 'indonesian_holidays';

    public const PROVIDER_NAME = 'Indonesian Holidays API';

    public const PROVIDER_URL = 'https://github.com/ahrulsyamil/indonesian-holidays';

    /** @return array{created: int, updated: int, deleted: int, preserved: int} */
    public function sync(int $year): array
    {
        if ($year < 1900 || $year > 9999) {
            throw new HolidayCalendarException('Tahun kalender harus berada antara 1900 dan 9999.');
        }

        $url = rtrim((string) config('holidays.api_url'), '/').'/holidays/'.$year;
        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('holidays.connect_timeout', 5))
                ->timeout((int) config('holidays.timeout', 15))
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new HolidayCalendarException('Sumber kalender nasional belum dapat dihubungi. Data kalender tersimpan tetap digunakan.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new HolidayCalendarException("Kalender nasional tahun {$year} belum dapat diambil. Silakan coba lagi nanti. Data tersimpan tetap digunakan.");
        }

        $payload = $response->json();
        if (is_array($payload) && ($payload['data'] ?? null) === []) {
            throw new HolidayCalendarException("Kalender nasional tahun {$year} belum tersedia pada penyedia. Kalender tersimpan tidak diubah.");
        }
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'meta' => ['required', 'array'],
            'meta.year' => ['required', 'integer', Rule::in([$year])],
            'meta.count' => ['required', 'integer', 'min:1', 'max:500'],
            'meta.sources' => ['sometimes', 'array', 'max:50'],
            'data' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'data.*' => ['required', 'array'],
            'data.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$year.'-01-01', 'before_or_equal:'.$year.'-12-31'],
            'data.*.name' => ['required', 'string', 'max:150'],
            'data.*.type' => ['required', Rule::in(['national_holiday', 'joint_leave'])],
        ]);
        if ($validator->fails() || count($payload['data']) !== (int) $payload['meta']['count']) {
            throw new HolidayCalendarException("Data kalender nasional tahun {$year} kosong atau tidak valid. Kalender tersimpan tidak diubah.");
        }

        $items = collect($payload['data'])->groupBy(fn (array $item) => $item['date'].'|'.$item['type'])
            ->map(function ($items) {
                $first = $items->first();
                $name = $items->pluck('name')->map(fn (string $name) => str($name)->squish()->toString())->unique()->implode(' / ');
                if ($name === '' || mb_strlen($name) > 150) {
                    throw new HolidayCalendarException('Nama hari libur dari sumber kalender tidak valid. Kalender tersimpan tidak diubah.');
                }

                return [
                    'tanggal' => $first['date'],
                    'nama' => $name,
                    'jenis' => $first['type'] === 'joint_leave' ? HariLibur::CUTI_BERSAMA : HariLibur::NASIONAL,
                ];
            })->values();
        $references = collect($payload['meta']['sources'] ?? [])
            ->filter(fn ($source) => is_array($source)
                && is_string($source['title'] ?? null) && mb_strlen($source['title']) <= 500
                && is_string($source['url'] ?? null) && mb_strlen($source['url']) <= 2048
                && filter_var($source['url'], FILTER_VALIDATE_URL)
                && parse_url($source['url'], PHP_URL_SCHEME) === 'https')
            ->map(fn (array $source) => ['title' => $source['title'], 'url' => $source['url']])->values()->all();

        return DB::transaction(function () use ($items, $references, $url, $year): array {
            $state = HolidayCalendarSync::query()->firstOrCreate(['year' => $year], ['source_url' => $url]);
            $state = HolidayCalendarSync::query()->lockForUpdate()->findOrFail($state->id);
            $existing = HariLibur::query()->whereYear('tanggal', $year)->lockForUpdate()->get()
                ->keyBy(fn (HariLibur $holiday) => $holiday->tanggal->toDateString().'|'.$holiday->jenis);
            $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'preserved' => 0];
            $keptIds = [];
            $syncedAt = now();

            foreach ($items as $item) {
                $holiday = $existing->get($item['tanggal'].'|'.$item['jenis']);
                if ($holiday && $holiday->source !== self::SOURCE) {
                    $counts['preserved']++;

                    continue;
                }

                if (! $holiday) {
                    $holiday = new HariLibur;
                    $counts['created']++;
                } elseif ($holiday->nama !== $item['nama']) {
                    $counts['updated']++;
                }

                $holiday->fill($item)->forceFill(['source' => self::SOURCE, 'synced_at' => $syncedAt])->save();
                $keptIds[] = $holiday->id;
            }

            $counts['deleted'] = HariLibur::query()->whereYear('tanggal', $year)->where('source', self::SOURCE)
                ->whereNotIn('id', $keptIds)->delete();
            $state->update(['source_url' => $url, 'source_references' => $references, 'synced_at' => $syncedAt]);

            return $counts;
        });
    }
}
