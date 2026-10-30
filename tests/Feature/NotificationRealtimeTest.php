<?php

namespace Tests\Feature;

use App\Services\Notifications\NotificationRealtimeService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class NotificationRealtimeTest extends TestCase
{
    public function test_notifications_emit_only_after_commit_to_the_owners_private_channel(): void
    {
        config(['mobile-realtime.enabled' => true]);
        $sent = [];
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->once()->andReturnUsing(function ($channels, $event, $payload) use (&$sent) {
            $sent[] = [(string) $channels[0], $event, $payload];
        });
        Broadcast::shouldReceive('connection')->with('reverb')->once()->andReturn($broadcaster);
        DB::beginTransaction();
        app(NotificationRealtimeService::class)->changed(42);
        $this->assertSame([], $sent);
        DB::commit();
        $this->assertSame('private-notifications.42', $sent[0][0]);
        $this->assertSame('notifications.changed', $sent[0][1]);
        $this->assertSame(['version'], array_keys($sent[0][2]));
    }

    public function test_rolled_back_notifications_are_not_advertised(): void
    {
        config(['mobile-realtime.enabled' => true]);
        Broadcast::shouldReceive('connection')->never();
        DB::beginTransaction();
        app(NotificationRealtimeService::class)->changed(42);
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_realtime_outage_does_not_fail_the_committed_submission(): void
    {
        config(['mobile-realtime.enabled' => true]);
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new \RuntimeException('offline'));
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        Log::shouldReceive('warning')->once();
        DB::transaction(fn () => app(NotificationRealtimeService::class)->changed(42));
        $this->assertSame(0, DB::transactionLevel());
    }
}
