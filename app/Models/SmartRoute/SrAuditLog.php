<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrAuditLog extends Model
{
    protected $table = 'v2_sr_audit_logs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'timestamp',
        'before_json' => 'json',
        'after_json' => 'json',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? time();
        });
    }

    /**
     * 记录审计日志
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $operatorId = null,
        string $operatorType = 'system',
        ?string $requestId = null,
        ?string $ip = null
    ): self {
        return self::create([
            'request_id' => $requestId,
            'operator_id' => $operatorId,
            'operator_type' => $operatorType,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason,
            'ip' => $ip,
        ]);
    }
}
