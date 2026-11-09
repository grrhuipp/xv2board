<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\NodeClientUaTestCase;

class UserTokenLookupIndexTest extends NodeClientUaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        require_once __DIR__ . '/../../database/migrations/2026_11_09_000001_add_user_token_lookup_index.php';
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('token', 32)->nullable();
        });
    }

    public function test_adds_lookup_index_without_changing_duplicate_tokens_and_is_repeatable(): void
    {
        DB::table('v2_user')->insert([['token' => 'same'], ['token' => 'same'], ['token' => null]]);
        $migration = new \AddUserTokenLookupIndex();
        $migration->up();
        $migration->up();

        $indexes = array_values(array_filter(Schema::getIndexes('v2_user'), fn ($index) => $index['name'] === 'idx_v2_user_token'));
        $this->assertCount(1, $indexes);
        $this->assertFalse($indexes[0]['unique']);
        $this->assertSame(2, DB::table('v2_user')->where('token', 'same')->count());
        $this->assertSame(3, DB::table('v2_user')->count());
        $migration->down();
        $this->assertTrue(Schema::hasIndex('v2_user', 'idx_v2_user_token'));
    }

    public function test_preserves_fresh_install_unique_index_without_a_duplicate(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->unique('token', 'token'));
        $before = Schema::getIndexes('v2_user');
        (new \AddUserTokenLookupIndex())->up();
        $this->assertSame($before, Schema::getIndexes('v2_user'));
    }

    public function test_reuses_existing_leading_token_index_under_a_different_name(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->index(['token', 'id'], 'existing_token_lookup'));
        $before = Schema::getIndexes('v2_user');
        (new \AddUserTokenLookupIndex())->up();
        $this->assertSame($before, Schema::getIndexes('v2_user'));
    }

    public function test_a_nonleading_token_column_does_not_satisfy_the_lookup(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->index(['id', 'token'], 'id_token'));
        (new \AddUserTokenLookupIndex())->up();
        $this->assertTrue(Schema::hasIndex('v2_user', 'idx_v2_user_token'));
    }
}
