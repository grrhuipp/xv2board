<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeLog extends Model
{
    public $timestamps = false;

    protected $table = 'v2_subscribe_log';

    protected $fillable = [
        'user_id',
        'email',
        'ip',
        'as',
        'isp',
        'country',
        'city',
        'user_agent',
        'created_at',
    ];
}
