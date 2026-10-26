<?php

namespace App\Services;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MobileDataChanges
{
    private bool $dirty = false;

    public function record(QueryExecuted $query): void
    {
        if (! config('mobile-realtime.enabled')) {
            return;
        }

        // Query Builder bulk updates and pivot syncs bypass Eloquent observers.
        if (! preg_match('/^\s*(?:insert(?:\s+(?:ignore|or\s+\w+))?\s+into|replace\s+into|update(?:\s+ignore)?|delete\s+from|truncate(?:\s+table)?)\s+([`"\[\]\w.]+)/i', $query->sql, $matches)) {
            return;
        }
        $parts = explode('.', str_replace(['`', '"', '[', ']'], '', $matches[1]));
        $table = strtolower(end($parts));
        $prefix = $query->connection->getTablePrefix();
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }
        $ignored = array_merge(config('mobile-realtime.ignored_tables', []), array_filter([
            config('session.table'), config('cache.stores.database.table'), config('cache.stores.database.lock_table'),
            config('queue.connections.database.table'), config('queue.batching.table'), config('queue.failed.table'),
        ]));
        if (in_array($table, $ignored, true)) {
            return;
        }

        // A rolled-back transaction must never advertise changes that did not persist.
        $query->connection->afterCommit(function (): void {
            $this->dirty = true;
        });
    }

    public function flush(): void
    {
        if (! $this->dirty) {
            return;
        }
        $this->dirty = false;
        if (! config('mobile-realtime.enabled')) {
            return;
        }

        try {
            // Only an invalidation signal is shared: no IDs, SQL, or personal data.
            // Each mobile page re-fetches its own data through normal authorization.
            Broadcast::connection('reverb')->broadcast(
                [new PrivateChannel('mobile.data')],
                'mobile.data.changed',
                ['version' => (string) Str::ulid()],
            );
        } catch (Throwable $exception) {
            Log::warning('Realtime mobile tidak tersedia; polling tetap aktif.', ['exception' => $exception::class]);
        }
    }
}
