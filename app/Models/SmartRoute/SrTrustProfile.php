<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrTrustProfile extends Model
{
    protected $table = 'v2_sr_trust_profiles';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'last_evaluated_at' => 'timestamp',
        'last_upgraded_at' => 'timestamp',
        'last_downgraded_at' => 'timestamp',
        'behavior_score' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function deviceProfile()
    {
        return $this->belongsTo(SrDeviceProfile::class, 'device_id', 'device_id');
    }
}
