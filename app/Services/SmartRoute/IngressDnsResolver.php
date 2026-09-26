<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * 入口域名 DNS 解析器（探活专用）。
 *
 * 关键约束：探活必须拿到「当前最新」的全量 A 记录，绝不能吃后端本机的
 * DNS 缓存 / TTL。因此优先走 DoH（DNS over HTTPS）JSON 接口直接向公共
 * DNS（默认 Cloudflare 1.1.1.1）查询，绕过本机 resolver 与系统缓存。
 *
 * 解析优先级：
 *   1) host 本身是 IP → 直接返回（filter_var 校验）。
 *   2) DoH JSON 查询全量 A 记录（不经本机缓存，每次拿最新）。
 *   3) DoH 失败降级到 dns_get_record($host, DNS_A) 作为兜底。
 *
 * 防 SSRF：仅对「已配置入口池 host」解析；可选过滤私网/保留地址。
 */
final class IngressDnsResolver
{
    /** DoH 上游候选（按序尝试，命中即止）。 */
    private array $upstreams;

    /** DoH 请求超时（秒）。 */
    private float $timeout;

    private ?Client $client;

    public function __construct(?array $upstreams = null, ?float $timeout = null, ?Client $client = null)
    {
        $cfgUpstreams = (array)config('smartroute.ingress_probe.doh_upstreams', []);
        $this->upstreams = $upstreams
            ?: (!empty($cfgUpstreams) ? $cfgUpstreams : ['https://1.1.1.1/dns-query']);
        $this->timeout = $timeout
            ?? (float)config('smartroute.ingress_probe.doh_timeout_ms', 3000) / 1000;
        $this->client = $client;
    }

    /**
     * 解析 host 的全量 A 记录 IP 列表。
     * host 已是 IP 时原样返回；解析不到返回空数组。
     *
     * @param bool $filterPrivate 是否过滤私网/保留地址（防 DNS 投毒指向内网）
     * @return string[]
     */
    public function resolveIps(string $host, bool $filterPrivate = true): array
    {
        $host = trim($host);
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (!$filterPrivate || $this->isPublicIp($host)) ? [$host] : [];
        }

        $ips = $this->resolveViaDoh($host);
        if (empty($ips)) {
            $ips = $this->resolveViaSystem($host);
        }

        $ips = array_values(array_unique($ips));
        if ($filterPrivate) {
            $ips = array_values(array_filter($ips, fn (string $ip) => $this->isPublicIp($ip)));
        }

        return $ips;
    }

    /**
     * DoH JSON 查询全量 A 记录。逐个上游尝试，任一成功即返回。
     * @return string[]
     */
    private function resolveViaDoh(string $host): array
    {
        $client = $this->client ?: new Client();

        foreach ($this->upstreams as $upstream) {
            try {
                $resp = $client->request('GET', $upstream, [
                    'query'   => ['name' => $host, 'type' => 'A'],
                    'headers' => ['Accept' => 'application/dns-json'],
                    'timeout' => $this->timeout,
                    'http_errors' => false,
                ]);

                if ($resp->getStatusCode() !== 200) {
                    continue;
                }

                $data = json_decode((string)$resp->getBody(), true);
                if (!is_array($data) || empty($data['Answer']) || !is_array($data['Answer'])) {
                    continue;
                }

                $ips = [];
                foreach ($data['Answer'] as $answer) {
                    // type=1 即 A 记录
                    if ((int)($answer['type'] ?? 0) !== 1) {
                        continue;
                    }
                    $ip = trim((string)($answer['data'] ?? ''));
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }

                if (!empty($ips)) {
                    return array_values(array_unique($ips));
                }
            } catch (\Throwable $e) {
                Log::warning('[IngressDnsResolver] DoH query failed', [
                    'upstream' => $upstream,
                    'host'     => $host,
                    'msg'      => $e->getMessage(),
                ]);
                continue;
            }
        }

        return [];
    }

    /**
     * 系统 resolver 兜底（会吃本机缓存，仅在 DoH 全部失败时使用）。
     * @return string[]
     */
    private function resolveViaSystem(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (!empty($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $r['ip'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 是否为公网可路由 IP（过滤私网 / 回环 / 链路本地 / 保留地址）。
     */
    private function isPublicIp(string $ip): bool
    {
        return (bool)filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
