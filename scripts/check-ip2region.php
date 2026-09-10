<?php

// Standalone database validation, without Laravel/Redis/vendor dependencies.
require_once __DIR__ . '/../app/Services/Geo/Ip2Region.php';

$directory = $argv[1] ?? __DIR__ . '/../storage/app/ip2region';
$geo = new \App\Services\Geo\Ip2Region($directory);
foreach ([4 => '223.5.5.5', 6 => '2400:3200::1'] as $version => $ip) {
    $file = $directory . '/ip2region-city-asn-org-v' . $version . '.xdb';
    if (!is_readable($file)) {
        throw new RuntimeException('Missing XDB: ' . $file);
    }
    // query() gracefully handles errors in Laravel; fail loudly in this CLI.
    $reader = new ReflectionMethod($geo, 'reader');
    $reader->setAccessible(true);
    $reader->invoke($geo, $version);
    $record = $geo->query($ip);
    if ($record === null || $record['as_number'] === null) {
        throw new RuntimeException('No ASN found for IPv' . $version . ' sample');
    }
    echo json_encode(['ip' => $ip, 'record' => $record, 'sha256' => hash_file('sha256', $file)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
