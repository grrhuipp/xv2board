<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrTelemetryEvent extends Model
{
    protected $table = 'v2_sr_telemetry_events';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'timestamp',
        'occurred_at' => 'timestamp',
        'meta_json' => 'json',
    ];

    /**
     * 手动设置 created_at
     */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? time();
        });
    }
}
