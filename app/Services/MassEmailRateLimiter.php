<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MassEmailRateLimiter
{
    const LIMIT = 30;
    const WINDOW_MS = 60000;

    private $key;

    public function __construct($key = 'send_email_mass:mail_send_window')
    {
        $this->key = $key;
    }

    /** Returns 0 when admitted, otherwise the seconds until a slot is available. */
    public function acquire()
    {
        $member = (string) Str::uuid();
        $result = Redis::connection('default')->eval(<<<'LUA'
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
local window = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
local count = redis.call('ZCARD', KEYS[1])
if count >= limit then
    local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
    return math.max(1, math.ceil((tonumber(oldest[2]) + window - now) / 1000))
end
redis.call('ZADD', KEYS[1], now, ARGV[3])
redis.call('PEXPIRE', KEYS[1], window)
return 0
LUA
            , 1, $this->key, self::WINDOW_MS, self::LIMIT, $member);

        return (int) $result;
    }
}
