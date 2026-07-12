<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2UserConnectLog extends Model
{
    protected $table = 'v2_user_connect_log';

    protected $fillable = [
        'user_id',
        'email',
        'ip',
        'as_number',
        'as_name',
        'country',
        'region',
    ];
}
