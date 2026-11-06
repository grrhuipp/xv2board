<?php

namespace Tests\Unit;

use App\Services\NodeClientUa;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/NodeClientUa.php';

class NodeClientUaTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('matchingCases')]
    public function test_matching($keywords, ?string $ua, bool $expected): void
    {
        $this->assertSame($expected, NodeClientUa::allows($keywords, $ua));
    }

    public static function matchingCases(): array
    {
        return [
            [null, null, true],
            [[], 'Unknown/1', true],
            [['Clash', 'Shadowrocket'], 'shadowROCKET/2.2', true],
            [[' Clash '], 'ClashMeta/1.0', true],
            [['Clash'], 'Surge/5', false],
            [['Clash'], null, false],
            [['Clash'], '', false],
            [['.*'], 'Surge/5', false],
            [[''], 'Clash/1', false],
            ['invalid', 'Clash/1', false],
        ];
    }

    public function test_filter_preserves_order_and_reindexes_results(): void
    {
        $servers = [
            ['id' => 1, 'client_ua_group_ids' => [1]],
            ['id' => 2],
            ['id' => 3, 'client_ua_group_ids' => [2]],
        ];
        $groups = [1 => ['Surge'], 2 => ['Clash']];
        $this->assertSame([$servers[1], $servers[2]], NodeClientUa::filter($servers, 'Clash/1', $groups));
        $this->assertSame([$servers[1]], NodeClientUa::filter($servers, null, $groups));
        $this->assertSame([], NodeClientUa::filter([$servers[0]], 'Clash/1', $groups));
    }

    public function test_group_selection_matches_any_group_and_denies_missing_groups(): void
    {
        $groups = [1 => ['Clash', 'Mihomo'], 2 => ['Surge'], 3 => []];
        $this->assertTrue(NodeClientUa::allowsGroups([1, 2], $groups, 'Surge/5'));
        $this->assertTrue(NodeClientUa::allowsGroups([1], $groups, 'Mihomo/1'));
        $this->assertFalse(NodeClientUa::allowsGroups([99], $groups, 'Clash/1'));
        $this->assertFalse(NodeClientUa::allowsGroups([3], $groups, 'Clash/1'));
        $this->assertFalse(NodeClientUa::allowsGroups([1], $groups, null));
        $this->assertTrue(NodeClientUa::allowsGroups([], $groups, null));
    }
}
