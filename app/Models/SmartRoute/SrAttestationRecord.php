<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrAttestationRecord extends Model
{
    protected $table = 'v2_sr_attestation_records';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'verified_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'verdict_json' => 'json',
        'risk_flags_json' => 'json',
    ];

    public function deviceProfile()
    {
        return $this->belongsTo(SrDeviceProfile::class, 'device_id', 'device_id');
    }

    /**
     * 是否验证通过且未过期
     */
    public function isValid(): bool
    {
        return $this->attestation_status === 'verified'
            && ($this->expires_at === null || $this->expires_at > time());
    }
}
