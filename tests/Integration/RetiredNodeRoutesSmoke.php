<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')) {
    throw new RuntimeException('Run with APP_ENV=testing.');
}
set_exception_handler(function ($e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});
$prefix = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
foreach (['fetch' => 'GET', 'save' => 'POST', 'drop' => 'POST'] as $action => $method) {
    try {
        app('router')->getRoutes()->match(Illuminate\Http\Request::create('/api/v1/' . $prefix . '/server/route/' . $action, $method));
        throw new RuntimeException('Retired route is still registered: ' . $action);
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
        echo "PASS retired endpoint returns 404: {$action}\n";
    }
}
$reflection = new ReflectionClass(App\Http\Controllers\V1\Server\UniProxyController::class);
foreach (['anytls', 'shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria'] as $type) {
    $controller = $reflection->newInstanceWithoutConstructor();
    $node = new App\Models\ServerAnytls([
        'server_port' => 12345,
        'host' => 'example.invalid',
        'cipher' => 'aes-128-gcm',
        'version' => 2,
        // Existing databases may retain this obsolete attribute.
        'route_id' => '[1]',
    ]);
    foreach (['nodeType' => $type, 'nodeInfo' => $node] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
    $request = Illuminate\Http\Request::create('/');
    $request->headers->set('If-None-Match', '');
    $response = $controller->config($request);
    $data = json_decode($response->getContent(), true);
    if ($response->getStatusCode() !== 200 || isset($data['routes']) || !isset($data['base_config']) || $data['server_port'] !== 12345) {
        throw new RuntimeException('Node config regression: ' . $type);
    }
    echo "PASS {$type} config works without route storage\n";
}
echo "ALL RETIRED NODE ROUTE CHECKS PASSED\n";
