<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNodeClientUaKeywords extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_server_client_ua_group')) {
            Schema::create('v2_server_client_ua_group', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->text('keywords');
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_server_client_ua_group');
    }
}
