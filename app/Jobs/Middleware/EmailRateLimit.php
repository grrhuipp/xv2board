<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;
use Exception;

class EmailRateLimit
{
    protected int $minuteLimit = 60;
    protected int $hourLimit = 3000;
    protected int $randomBuffer = 5;

    public function handle($job, $next)
    {
        try {
            $queueName = $job->queue ?? 'default';
            $now = CarbonImmutable::now();

            if ($this->checkRateLimit($queueName, $now)) {
                $this->incrementCurrentSlot($queueName, $now);
                return $next($job);
            }

            $nextSlot = $this->allocateFutureSlotLua(
                $queueName,
                intval($now->format('YmdHi')),
                intval($now->format('YmdH'))
            );

            $currentSlot = intval($now->format('YmdHi'));
            if ($nextSlot < $currentSlot) {
                $nextSlot = $this->getNextValidSlot($currentSlot);
            }

            $slotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
            $delaySeconds = max(1, $slotTime->timestamp - $now->timestamp + rand(0, $this->randomBuffer));

            dispatch(new SendEmailJob($job->getParams(), $queueName))->delay($now->addSeconds($delaySeconds));
            $job->delete();

        } catch (Exception $e) {
            Log::error('邮件限流中间件错误', [
                'error' => $e->getMessage(),
                'job' => get_class($job),
                'queue' => $job->queue ?? 'default'
            ]);
            return $next($job);
        }
    }

    protected function checkRateLimit(string $queueName, CarbonImmutable $now): bool
    {
        $minuteKey = $this->getCurrentMinuteKey($queueName, $now);
        $hourKey   = $this->getCurrentHourKey($queueName, $now);

        $minuteCount = intval(Redis::get($minuteKey) ?? 0);
        $hourCount   = intval(Redis::get($hourKey) ?? 0);

        return ($minuteCount < $this->minuteLimit && $hourCount < $this->hourLimit);
    }

    protected function incrementCurrentSlot(string $queueName, CarbonImmutable $now)
    {
        $minuteKey = $this->getCurrentMinuteKey($queueName, $now);
        $hourKey   = $this->getCurrentHourKey($queueName, $now);

        Redis::incr($minuteKey);
        Redis::incr($hourKey);

        Redis::expire($minuteKey, max(1, 120 - $now->second));
        Redis::expire($hourKey, max(1, 7200 - ($now->minute * 60 + $now->second)));
    }

    protected function getNextValidSlot(int $currentSlot): int
    {
        $year = intval(substr($currentSlot, 0, 4));
        $month = intval(substr($currentSlot, 4, 2));
        $day = intval(substr($currentSlot, 6, 2));
        $hour = intval(substr($currentSlot, 8, 2));
        $minute = intval(substr($currentSlot, 10, 2));

        $currentTime = CarbonImmutable::createFromFormat('Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute)
        );
        $nextTime = $currentTime->addMinute();
        return intval($nextTime->format('YmdHi'));
    }

    protected function allocateFutureSlotLua(string $queueName, int $currentSlot, int $currentHour): int
    {
        $nextSlotKey = "rate_limit:{$queueName}:next_slot";
        $futureMinuteBaseKey = "rate_limit:{$queueName}:future_minute:";
        $futureHourBaseKey   = "rate_limit:{$queueName}:future_hour:";

        $luaScript = $this->getFutureSlotAllocationScript();

        try {
            $nextSlot = intval(Redis::eval(
                $luaScript,
                3,
                $nextSlotKey,
                $futureMinuteBaseKey,
                $futureHourBaseKey,
                $this->minuteLimit,
                $this->hourLimit,
                $currentSlot,
                $currentHour
            ));
            return $nextSlot;
        } catch (Exception $e) {
            Log::error('Lua脚本执行失败', ['error' => $e->getMessage()]);
            return $this->getNextValidSlot($currentSlot);
        }
    }

    protected function getCurrentMinuteKey(string $queueName, CarbonImmutable $now): string
    {
        return "rate_limit:{$queueName}:current_minute:" . $now->format('YmdHi');
    }

    protected function getCurrentHourKey(string $queueName, CarbonImmutable $now): string
    {
        return "rate_limit:{$queueName}:current_hour:" . $now->format('YmdH');
    }

    protected function getFutureSlotAllocationScript(): string
    {
        return <<<'LUA'
            local next_slot_key = KEYS[1]
            local future_minute_base = KEYS[2]
            local future_hour_base = KEYS[3]
    
            local minute_limit = tonumber(ARGV[1])
            local hour_limit = tonumber(ARGV[2])
            local current_slot = tonumber(ARGV[3])
            local current_hour = tonumber(ARGV[4])
    
            local month_days = {31,28,31,30,31,30,31,31,30,31,30,31}
    
            local function is_leap_year(year)
                return (year % 4 == 0 and year % 100 ~= 0) or (year % 400 == 0)
            end
    
            local function get_month_days(year, month)
                if month == 2 and is_leap_year(year) then
                    return 29
                end
                return month_days[month]
            end
    
            local function get_next_valid_slot(slot)
                local year = math.floor(slot / 100000000)
                local month = math.floor((slot % 100000000) / 1000000)
                local day = math.floor((slot % 1000000) / 10000)
                local hour = math.floor((slot % 10000) / 100)
                local minute = slot % 100
    
                minute = minute + 1
                if minute >= 60 then
                    minute = 0
                    hour = hour + 1
                    if hour >= 24 then
                        hour = 0
                        day = day + 1
                        local max_days = get_month_days(year, month)
                        if day > max_days then
                            day = 1
                            month = month + 1
                            if month > 12 then
                                month = 1
                                year = year + 1
                            end
                        end
                    end
                end
    
                return year * 100000000 + month * 1000000 + day * 10000 + hour * 100 + minute
            end
    
            local function slot_to_minutes(slot)
                local year = math.floor(slot / 100000000)
                local month = math.floor((slot % 100000000) / 1000000)
                local day = math.floor((slot % 1000000) / 10000)
                local hour = math.floor((slot % 10000) / 100)
                local minute = slot % 100
    
                local days = 0
                for y = 2025, year - 1 do
                    days = days + (is_leap_year(y) and 366 or 365)
                end
                for m = 1, month - 1 do
                    days = days + get_month_days(year, m)
                end
                days = days + day - 1
    
                return days * 1440 + hour * 60 + minute
            end
    
            local stored_next_slot = redis.call("GET", next_slot_key)
            local next_slot
    
            if not stored_next_slot or stored_next_slot == false then
                next_slot = get_next_valid_slot(current_slot)
                redis.call("SET", next_slot_key, next_slot)
                redis.call("EXPIRE", next_slot_key, 7200)
            else
                stored_next_slot = tonumber(stored_next_slot)
                if stored_next_slot <= current_slot then
                    next_slot = get_next_valid_slot(current_slot)
                    redis.call("SET", next_slot_key, next_slot)
                    redis.call("EXPIRE", next_slot_key, 7200)
                else
                    next_slot = stored_next_slot
                end
            end
    
            local search_count = 0
            local found_slot = nil
            local max_search = 240
    
            while search_count < max_search do
                local minute_key = future_minute_base .. next_slot
                local hour_slot = math.floor(next_slot / 100)
                local hour_key = future_hour_base .. hour_slot
    
                local minute_count = tonumber(redis.call("GET", minute_key) or "0")
                local hour_count = tonumber(redis.call("GET", hour_key) or "0")
    
                if minute_count < minute_limit and hour_count < hour_limit then
                    minute_count = redis.call("INCR", minute_key)
                    hour_count = redis.call("INCR", hour_key)
    
                    local slot_minutes = slot_to_minutes(next_slot)
                    local current_minutes = slot_to_minutes(current_slot)
                    local minutes_diff = slot_minutes - current_minutes
    
                    local minute_ttl = math.max(120, (minutes_diff + 2) * 60)
                    redis.call("EXPIRE", minute_key, minute_ttl)
                    local hour_ttl = math.max(7200, (minutes_diff + 120) * 60)
                    redis.call("EXPIRE", hour_key, hour_ttl)
    
                    found_slot = next_slot
    
                    if minute_count >= minute_limit then
                        local new_pointer = get_next_valid_slot(next_slot)
                        redis.call("SET", next_slot_key, new_pointer)
                        redis.call("EXPIRE", next_slot_key, 7200)
                    elseif hour_count >= hour_limit then
                        local next_hour = hour_slot + 1
                        local y = math.floor(next_hour / 1000000)
                        local mh = next_hour % 1000000
                        local m = math.floor(mh / 10000)
                        local h = mh % 10000
                        if h >= 24 then
                            h = 0
                            m = m + 1
                            if m > 12 then
                                m = 1
                                y = y + 1
                            end
                        end
                        local new_pointer = (y * 1000000 + m * 10000 + h) * 100
                        redis.call("SET", next_slot_key, new_pointer)
                        redis.call("EXPIRE", next_slot_key, 7200)
                    end
                    break
                end
    
                next_slot = get_next_valid_slot(next_slot)
                search_count = search_count + 1
            end
    
            if found_slot then
                return found_slot
            else
                redis.call("SET", next_slot_key, next_slot)
                redis.call("EXPIRE", next_slot_key, 7200)
                return next_slot
            end
        LUA;
    }    
}
