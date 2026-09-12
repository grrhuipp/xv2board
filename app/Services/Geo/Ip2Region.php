<?php

declare(strict_types=1);

namespace App\Services\Geo;

use ip2region\xdb\Searcher;
use ip2region\xdb\Util;

require_once __DIR__ . '/../../../library/ip2region/Searcher.class.php';

/** Reads the city/ASN/org XDB dataset (not the standard city/ISP dataset). */
class Ip2Region
{
    private static $instance;
    private $directory;
    private $readers = [];
    private $lastWarning = 0;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? (string) config(
            'ip2region.database_path', storage_path('app/ip2region')
        ), '/\\');
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __destruct()
    {
        foreach ($this->readers as $reader) {
            $reader->close();
        }
    }

    /**
     * Missing data must not prevent subscriptions or connection reporting.
     * @return array{as_number:?string,as_name:?string,country:?string,province:?string,city:?string,area:?string,isp:?string}|null
     */
    public function query(string $ip): ?array
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // Treat IPv4-mapped IPv6 addresses as the underlying IPv4 address.
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        $version = strpos($ip, ':') === false ? 4 : 6;

        try {
            $record = $this->reader($version)->search($ip);
            return $this->parseRecord($record);
        } catch (\Throwable $e) {
            if (time() - $this->lastWarning >= 300) {
                $this->lastWarning = time();
                \Log::warning('ip2region local database lookup failed', [
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }

    private function reader(int $version): Searcher
    {
        if (isset($this->readers[$version])) {
            return $this->readers[$version];
        }
        $file = $this->directory . '/v' . $version . '.xdb';
        if (!is_readable($file)) {
            throw new \RuntimeException('XDB file is not readable: ' . basename($file));
        }
        $header = Util::loadHeaderFromFile($file);
        if ($header === null || Util::verifyFromFile($file) !== null) {
            throw new \RuntimeException('Invalid XDB header: ' . basename($file));
        }
        $ipVersion = Util::versionFromHeader($header);
        $size = filesize($file);
        if ($ipVersion->bytes !== ($version === 4 ? 4 : 16)
            || $header['startIndexPtr'] < 256 + 256 * 256 * 8
            || $header['endIndexPtr'] < $header['startIndexPtr']
            || $header['endIndexPtr'] + $ipVersion->segmentIndexSize > $size) {
            throw new \RuntimeException('XDB version or index bounds mismatch: ' . basename($file));
        }
        // Only retain the 512 KiB vector index per family, not the entire XDB.
        $index = Util::loadVectorIndexFromFile($file);
        if ($index === null || strlen($index) !== 256 * 256 * 8) {
            throw new \RuntimeException('Unable to read XDB vector index');
        }
        return $this->readers[$version] = Searcher::newWithVectorIndex($ipVersion, $file, $index);
    }

    private function parseRecord(string $record): ?array
    {
        if ($record === '') {
            return null;
        }
        $parts = explode('|', $record);
        if (count($parts) !== 5) {
            throw new \RuntimeException('Expected country|province|city|ASN|organization XDB record');
        }
        $parts = array_map(static function (string $value): ?string {
            $value = trim($value);
            return $value === '' || $value === '0' ? null : $value;
        }, $parts);
        [$country, $province, $city, $asn, $org] = $parts;
        if ($asn !== null) {
            if (!preg_match('/^(?:AS)?([0-9]+)$/i', $asn, $matches)) {
                throw new \RuntimeException('Invalid ASN field; incompatible XDB dataset');
            }
            $asn = ltrim($matches[1], '0') ?: null;
        }
        if ($country === null && $province === null && $city === null && $asn === null && $org === null) {
            return null;
        }
        return [
            'as_number' => $asn,
            'as_name' => $org,
            'country' => $country,
            'province' => $province,
            'city' => $city,
            'area' => null,
            'isp' => $org,
        ];
    }
}
