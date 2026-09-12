<?php
// Run only against a disposable database: APP_ENV=testing php tests/Integration/AppSmartRouteSmoke.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Plan;
use App\Models\SmartRoute\SrDeviceProfile;
if (!app()->environment('testing') || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) {
    throw new RuntimeException('Use a disposable qa_ database with APP_ENV=testing.');
}
set_exception_handler(function ($e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
function check($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
config(['v2board.secure_path' => 'qa-admin', 'v2board.app_name' => 'QA', 'v2board.show_info_to_server_enable' => 0]);
$routes = app('router')->getRoutes(); $count = 0;
foreach ($routes as $route) {
    if (strpos($route->uri(), 'jiuxiang') === false && strpos($route->uri(), 'smart-route') === false && strpos($route->uri(), 'getAppAudience') === false) continue;
    $action = $route->getActionName();
    if (strpos($action, '@') !== false) { [$class, $method] = explode('@', $action); if (!class_exists($class) || !method_exists($class, $method)) throw new RuntimeException('Missing route action: '.$route->uri()); }
    $count++;
}
check($count >= 70, 'App and SmartRoute route registration');
require_once database_path('migrations/2026_09_10_000001_install_app_smartroute.php');
(new InstallAppSmartroute())->up();
check(true, 'schema migration can run twice');
DB::statement('ALTER TABLE v2_sr_device_profiles DROP COLUMN os_version');
(new InstallAppSmartroute())->up();
check(Illuminate\Support\Facades\Schema::hasColumn('v2_sr_device_profiles','os_version'),'partial schema upgrade restores missing columns');
$expectedSettings=json_decode(file_get_contents(database_path('schema/app-smartroute-settings.json')),true);
foreach ($expectedSettings as $setting) {
    $stored=DB::table('v2_sr_settings')->where('section',$setting['section'])->where('key_name',$setting['key_name'])->value('value_json');
    check(json_decode($stored,true)===json_decode($setting['value_json'],true),'source policy '.$setting['section'].'.'.$setting['key_name']);
}
DB::beginTransaction();
try {
    $pool=App\Models\SmartRoute\SrIngressPool::create(['name'=>'QA configured pool','host'=>'configured.example.invalid','port'=>443,'status'=>1]);
    App\Models\SmartRoute\SrIngressMap::create(['server_type'=>'anytls','server_id'=>9001,'exposure_tier'=>'intl_only','pool_id'=>$pool->id,'ingress_host'=>'','status'=>1]);
    $resolver=new App\Services\SmartRoute\SmartRouteIngressResolver();
    $address=$resolver->resolveIngress('anytls',9001,'intl_only');
    check($address===['host'=>'configured.example.invalid','port'=>443],'pool uses configured domain without health snapshots');
    $pool->port=null; $pool->save();
    check($resolver->resolveIngress('anytls',9001,'intl_only')['port']===null,'unset pool port preserves node port');
    $pool->status=0; $pool->save();
    check($resolver->resolveIngress('anytls',9001,'intl_only')===null,'disabled empty pool mapping falls back');
    $mapping=App\Models\SmartRoute\SrIngressMap::where('server_id',9001)->first();
    $mapping->ingress_host='fallback.example.invalid'; $mapping->ingress_port=8443; $mapping->save();
    check($resolver->resolveIngress('anytls',9001,'intl_only')===['host'=>'fallback.example.invalid','port'=>8443],'explicit address survives disabled pool');
    check(!class_exists(App\Console\Commands\IngressProbe::class),'active probe command removed');
    $plan = Plan::create(['name'=>'QA','group_id'=>1,'transfer_enable'=>10,'month_price'=>1000,'show'=>1,'renew'=>1,'device_limit'=>2]);
    $user = User::create(['email'=>'app-smoke@example.invalid','password'=>password_hash('qa-password-only', PASSWORD_DEFAULT),'token'=>'qa-subscription-token','uuid'=>'11111111-1111-4111-8111-111111111111','plan_id'=>$plan->id,'group_id'=>1,'transfer_enable'=>10737418240,'u'=>0,'d'=>0,'expired_at'=>time()+86400,'device_limit'=>2,'banned'=>0]);
    $other = User::create(['email'=>'other-smoke@example.invalid','password'=>'unused','token'=>'qa-other-token','uuid'=>'22222222-2222-4222-8222-222222222222','device_limit'=>2,'banned'=>0]);
    $request = Request::create('/api/v1/jiuxiang/smart-route/device/register', 'POST', ['install_id'=>'qa-install','platform'=>'android','app_version'=>'1.2.0','network_type'=>'wifi']);
    $action = new App\Actions\SmartRoute\RegisterDeviceAction();
    $response = $action->execute($user, $request);
    check($response->getStatusCode() === 200, 'SmartRoute device registration');
    $device = SrDeviceProfile::where('user_id',$user->id)->firstOrFail();
    $id = $device->device_id;
    $action->execute($user, $request);
    check(SrDeviceProfile::where('user_id',$user->id)->count()===1, 'device registration is idempotent');
    $request->merge(['device_id'=>$id]); $action->execute($other, $request);
    check(SrDeviceProfile::where('device_id',$id)->first()->user_id === $user->id, 'device cannot be taken over by another account');
    $manifestRequest = Request::create('/', 'POST', ['network_type'=>'wifi','app_version'=>'1.2.0']);
    $manifest = (new App\Actions\SmartRoute\ResolveManifestAction())->execute($user,$device,$manifestRequest);
    check($manifest->getStatusCode()===200, 'manifest resolution');
    $status = (new App\Services\AppClient\AccountStatusService())->checkAccountStatus($user);
    check($status['status_ok'], 'App account status');
    $cipher = new App\Services\AppClient\AppClientResponseAdapter();
    $encrypted = $cipher->encrypt(['smoke'=>'ok']);
    $decoded = openssl_decrypt($encrypted,config('appclient.encryption.cipher'),config('appclient.encryption.key'),0,config('appclient.encryption.iv'));
    check(json_decode($decoded,true)===['smoke'=>'ok'], 'source App encryption configuration round trip');
    $builder = new App\Services\AppClient\ClashConfigBuilder();
    check(is_string($builder->buildClashConfig($user,$id)), 'App subscription configuration');

    $loginRequest = Request::create('/api/v1/jiuxiang/login','POST',['email'=>$user->email,'password'=>'qa-password-only','device_id'=>$id,'install_id'=>'qa-install','app_version'=>'1.2.0','os_type'=>'android']);
    $login=(new App\Services\AppClient\AppClientAuthService())->login($loginRequest);
    check((json_decode($login->getContent(), true)['status'] ?? 0)===1,'App login with current device');
    $sync=(new App\Services\AppClient\AppClientAuthService())->sync($user,$loginRequest);
    check((json_decode($sync->getContent(), true)['status'] ?? 0)===1,'App sync response');
    $gift = App\Models\Giftcard::create(['name'=>'QA gift','code'=>'qa-card','type'=>1,'value'=>100,'limit_use'=>1,'started_at'=>time()-60,'ended_at'=>time()+3600]);
    $giftResponse=(new App\Services\AppClient\AppOrderService())->redeemGiftCard($user,Request::create('/','POST',['code'=>'qa-card']));
    check((json_decode($giftResponse->getContent(), true)['status'] ?? 0)===1,'App gift redemption against xv2board schema');
    check($user->fresh()->balance===100,'gift credits target balance');
    $repeat=(new App\Services\AppClient\AppOrderService())->redeemGiftCard($user,Request::create('/','POST',['code'=>'qa-card']));
    check((json_decode($repeat->getContent(), true)['status'] ?? 1)===0,'used gift card is rejected');
    $grant=App\Models\SmartRoute\SrProviderGrant::issue($user->id,$id,'intl_only',null,120,2);
    $grantService=new App\Services\SmartRoute\ProviderGrantService();
    check($grantService->consumeGrant($grant->grant_id,$user->id,$id)['success'],'provider grant consumption');
    check(!$grantService->consumeGrant($grant->grant_id,$other->id,$id)['success'],'provider grant ownership');

    $event=['event_type'=>'qa_smoke','occurred_at'=>date('c')];
    (new App\Jobs\SmartRouteTelemetryBatchJob($user->id,$id,[$event]))->handle();
    check(App\Models\SmartRoute\SrTelemetryEvent::where('user_id',$user->id)->where('event_type','qa_smoke')->exists(),'telemetry queue job writes events');
    $guard = new App\Http\Middleware\SmartRouteGuard();
    $body='{}'; $nonce='qa-nonce-'.bin2hex(random_bytes(8));
    $guardRequest=Request::create('/api/v1/jiuxiang/smart-route/device/register','POST',[],[],[],['HTTP_X_TIMESTAMP'=>(string)time(),'HTTP_X_NONCE'=>$nonce,'HTTP_X_BODY_SHA256'=>hash('sha256',$body)],$body);
    $next=fn()=>response()->json(['ok'=>true]);
    check($guard->handle($guardRequest,$next)->getStatusCode()===200,'valid signed-envelope headers');
    check($guard->handle($guardRequest,$next)->getStatusCode()!==200,'nonce replay rejection');
    $email='reset-smoke@example.invalid'; $key=App\Utils\CacheKey::get('EMAIL_VERIFY_CODE',$email); Cache::put($key,'123456',60);
    for($i=0;$i<5;$i++) App\Services\PasswordResetGuard::verifyCode($email,'654321');
    check(Cache::get($key)===null,'App reset code expires after five failures');
    $device->status=0; $device->save();
    $request->merge(['device_id'=>$id]);
    check($action->execute($user,$request)->getStatusCode()!==200,'unbound device cannot silently re-register');
    echo "ALL APP/SMARTROUTE SMOKE CHECKS PASSED\n";
} finally { DB::rollBack(); }
