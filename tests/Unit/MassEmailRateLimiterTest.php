<?php

namespace Tests\Unit;

use App\Services\MassEmailRateLimiter;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class MassEmailRateLimiterTest extends TestCase
{
    public function test_admitted_send_consumes_a_slot_in_the_shared_sliding_window()
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->once()->withArgs(function ($script, $keys, $key, $window, $limit, $member) {
            $this->assertSame(1, $keys);
            $this->assertSame('send_email_mass:mail_send_window', $key);
            $this->assertSame(60000, $window);
            $this->assertSame(30, $limit);
            $this->assertNotEmpty($member);
            $this->assertStringContainsString("ZREMRANGEBYSCORE", $script);
            $this->assertStringContainsString("ZADD", $script);
            $this->assertStringContainsString('if count >= limit then', $script);
            $this->assertStringContainsString("ZRANGE", $script);
            $this->assertStringContainsString("math.ceil", $script);
            return true;
        })->andReturn(0);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->assertSame(0, (new MassEmailRateLimiter())->acquire());
    }

    public function test_real_redis_rejects_the_31st_send_in_a_window()
    {
        try {
            $redis = Redis::connection('default');
            $redis->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }

        $key = 'send_email_mass:test:' . (string) \Illuminate\Support\Str::uuid();
        try {
            $limiter = new MassEmailRateLimiter($key);
            for ($i = 0; $i < 30; $i++) {
                $this->assertSame(0, $limiter->acquire(), 'The first 30 sends should be admitted.');
            }
            $this->assertGreaterThan(0, $limiter->acquire(), 'The 31st send must wait for the window.');
        } finally {
            $redis->del($key);
        }
    }

    public function test_overflow_returns_delay_instead_of_admitting_or_dropping_the_send()
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->once()->withArgs(function ($script, $keys, $key, $window, $limit, $member) {
            $this->assertStringContainsString('if count >= limit then', $script);
            $this->assertStringContainsString('return math.max(1, math.ceil', $script);
            $this->assertSame(30, $limit);
            return true;
        })->andReturn(17);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->assertSame(17, (new MassEmailRateLimiter())->acquire());
    }
}
