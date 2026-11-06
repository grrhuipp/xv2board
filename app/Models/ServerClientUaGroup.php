<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerClientUaGroup extends Model
{
    protected $table = 'v2_server_client_ua_group';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = ['keywords' => 'array'];
}
