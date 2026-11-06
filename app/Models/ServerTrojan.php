<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerTrojan extends Model
{
    use \App\Models\Concerns\HasClientUaGroups;
    protected $table = 'v2_server_trojan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'client_ua_group_ids' => 'array',
        'network_settings' => 'array',
        'tags' => 'array'
    ];
}
