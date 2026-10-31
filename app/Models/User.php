<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected static function booted()
    {
        static::updating(function (User $user) {
            $passwordChange = $user->isDirty(['password', 'password_algo', 'password_salt']);
            $credentialChange = $user->isDirty(['email', 'is_admin', 'is_staff']);
            if ($passwordChange || $credentialChange || ($user->isDirty('banned') && $user->banned)) {
                $user->app_legacy_token_disabled = 1;
            }
            if ($credentialChange || ($user->isDirty('banned') && $user->banned)) {
                // Password-only changes revoke sessions below while keeping
                // the subscription credential stable.
                $user->token = \App\Utils\Helper::guid();
            }
        });
        static::updated(function (User $user) {
            if ($user->wasChanged(['password', 'password_algo', 'password_salt', 'email', 'is_admin', 'is_staff', 'banned', 'token'])) {
                (new \App\Services\AuthService($user))->removeAllSession();
            }
        });
    }

    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
