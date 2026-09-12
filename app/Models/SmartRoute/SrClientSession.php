<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrClientSession extends Model
{
    protected $table = 'v2_sr_client_sessions';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'started_at' => 'timestamp',
        'ended_at' => 'timestamp',
        'effective_connected_seconds' => 'integer',
        'effective_bytes_up' => 'integer',
        'effective_bytes_down' => 'integer',
        'bidirectional_active_windows' => 'integer',
        'connection_presence_seconds' => 'integer',
        'burstiness_ratio' => 'float',
        'manual_batch_test_count' => 'integer',
        'manual_single_test_count' => 'integer',
        'probe_seconds' => 'integer',
        'distinct_destinations_count' => 'integer',
        'telemetry_suspicious' => 'integer',
    ];

    public function deviceProfile()
    {
        return $this->belongsTo(SrDeviceProfile::class, 'device_id', 'device_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
