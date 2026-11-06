<?php

namespace Tests\Unit;

use App\Models\ServerClientUaGroup;
use App\Models\ServerV2node;
use App\Services\NodeClientUa;
use Tests\NodeClientUaTestCase;

class NodeClientUaGroupsTest extends NodeClientUaTestCase
{
    public function test_migration_group_edits_and_node_copy_preserve_restrictions(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $this->app->instance('db.schema', $schema);
        $schema->create('v2_server_v2node', function ($table) {
            $table->increments('id');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        require_once __DIR__ . '/../../database/migrations/2026_11_06_000001_add_node_client_ua_keywords.php';
        $migration = new \AddNodeClientUaKeywords();
        $migration->up();
        $migration->up();
        $group = ServerClientUaGroup::create(['name' => 'Clash', 'keywords' => ['Clash', 'Mihomo']]);
        $node = ServerV2node::create(['client_ua_group_ids' => [$group->id]]);
        $public = ServerV2node::create([]);
        // Upgrade after SQL import or a previous migration must preserve saved data.
        $migration->up();
        $this->assertSame([$group->id], $node->fresh()->client_ua_group_ids);
        $this->assertSame(['Clash', 'Mihomo'], $group->fresh()->keywords);
        $servers = [$node->fresh()->toArray(), $public->fresh()->toArray()];
        $this->assertCount(2, NodeClientUa::filter($servers, 'Mihomo/1'));
        $this->assertCount(1, NodeClientUa::filter($servers, 'Surge/5'));
        $group->update(['keywords' => ['Surge']]);
        $this->assertCount(1, NodeClientUa::filter($servers, 'Mihomo/1'));
        $this->assertCount(2, NodeClientUa::filter($servers, 'Surge/5'));
        $copy = $node->replicate();
        $copy->save();
        $this->assertSame([$group->id], $copy->fresh()->client_ua_group_ids);
        $node->update(['client_ua_group_ids' => []]);
        $this->assertCount(1, NodeClientUa::filter([$node->fresh()->toArray()], null));
        $group->delete();
        $this->assertSame([], NodeClientUa::filter([$copy->fresh()->toArray()], 'Surge/5'));

        $factory = new \Illuminate\Validation\Factory($this->app['translator'], $this->app);
        $factory->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($this->app['db']));
        $this->app->instance('validator', $factory);
        $controller = new \App\Http\Controllers\V1\Admin\Server\ClientUaGroupController();
        $controller->save($this->request(['name' => ' Mobile ', 'keywords' => [' Shadowrocket ']]));
        $mobile = ServerClientUaGroup::first();
        $this->assertSame('Mobile', $mobile->name);
        $this->assertSame(['Shadowrocket'], $mobile->keywords);
        $node->update(['client_ua_group_ids' => [$mobile->id]]);
        try {
            $controller->drop($this->request(['id' => $mobile->id]));
            $this->fail('Referenced groups must not be deleted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $controller->save($this->request(['id' => $mobile->id, 'name' => 'Mobile', 'keywords' => ['Loon']]));
        $this->assertCount(1, NodeClientUa::filter([$node->fresh()->toArray()], 'Loon/1'));
        $this->assertSame([], NodeClientUa::filter([$node->fresh()->toArray()], 'Shadowrocket/1'));
        $node->update(['client_ua_group_ids' => null]);
        $controller->drop($this->request(['id' => $mobile->id]));
        $this->assertSame(0, ServerClientUaGroup::count());
    }

    private function request(array $params): \Illuminate\Http\Request
    {
        $request = new class extends \Illuminate\Http\Request {
            public function validate(array $rules)
            {
                return app('validator')->make($this->all(), $rules)->validate();
            }
        };
        $request->replace($params);
        return $request;
    }
}
