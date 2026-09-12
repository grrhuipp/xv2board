<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SrProviderGrant extends Model
{
    protected $table = 'v2_sr_provider_grants';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'last_fetched_at' => 'timestamp',
        'max_fetch_count' => 'integer',
        'fetched_count' => 'integer',
    ];

    /**
     * 创建新 grant
     */
    public static function issue(int $userId, string $deviceId, string $exposureTier, ?int $packageId, int $ttl, int $maxFetch): self
    {
        return self::create([
            'grant_id' => 'pg_' . Str::random(20),
            'user_id' => $userId,
            'device_id' => $deviceId,
            'exposure_tier' => $exposureTier,
            'provider_package_id' => $packageId,
            'max_fetch_count' => $maxFetch,
            'fetched_count' => 0,
            'expires_at' => time() + $ttl,
            'status' => 'active',
        ]);
    }

    /**
     * 7.4：签发或复用 grant。
     *
     * 当 $reuseWindowSeconds > 0 时，优先复用该用户+设备+暴露层下「最近签发、仍可用、
     * 在复用窗口内」的 active grant，避免每次 manifest 都新建一行 grant 导致表无限膨胀。
     * 窗口为 0（默认）时退化为原 issue() 行为，保持向后兼容。
     *
     * 同时按概率触发一次轻量过期清理（清理该设备已过期/用尽的旧 grant），避免堆积。
     */
    public static function issueOrReuse(
        int $userId,
        string $deviceId,
        string $exposureTier,
        ?int $packageId,
        int $ttl,
        int $maxFetch,
        int $reuseWindowSeconds = 0
    ): self {
        if ($reuseWindowSeconds > 0) {
            $now = time();
            /** @var self|null $existing */
            $existing = self::where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('exposure_tier', $exposureTier)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->whereColumn('fetched_count', '<', 'max_fetch_count')
                ->where('created_at', '>=', $now - $reuseWindowSeconds)
                ->orderByDesc('created_at')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        // 低频概率触发过期清理（约 2% 请求），避免每次 manifest 都扫描清理表。
        if (random_int(1, 50) === 1) {
            self::pruneExpired($userId, $deviceId);
        }

        return self::issue($userId, $deviceId, $exposureTier, $packageId, $ttl, $maxFetch);
    }

    /**
     * 清理某设备已过期/已用尽的历史 grant（保留最近少量，避免审计断档）。
     */
    public static function pruneExpired(int $userId, string $deviceId): int
    {
        $now = time();
        return (int)self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where(function ($q) use ($now) {
                $q->where('expires_at', '<', $now - 3600)
                    ->orWhere('status', '!=', 'active');
            })
            ->where('created_at', '<', $now - 3600)
            ->delete();
    }

    /**
     * 是否可用
     */
    public function isUsable(): bool
    {
        return $this->status === 'active'
            && $this->expires_at > time()
            && $this->fetched_count < $this->max_fetch_count;
    }

    /**
     * 记录一次拉取
     */
    public function recordFetch(?string $responseHash = null): void
    {
        $this->fetched_count += 1;
        $this->last_fetched_at = time();
        if ($responseHash) {
            $this->last_fetch_response_hash = $responseHash;
        }
        if ($this->fetched_count >= $this->max_fetch_count) {
            $this->status = 'used';
        }
        $this->save();
    }
}
