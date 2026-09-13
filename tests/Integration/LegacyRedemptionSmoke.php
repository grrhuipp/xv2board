<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Plan,Coupon,Order};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
if (!app()->environment('testing') || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) throw new RuntimeException('Disposable database required');
function checkRedeem($value, $label) { if (!$value) throw new RuntimeException($label); echo "PASS $label\n"; }
config(['v2board.email_verify'=>0,'v2board.recaptcha_enable'=>0,'v2board.stop_register'=>0,'v2board.invite_force'=>0,'v2board.email_whitelist_enable'=>0,'v2board.register_limit_by_ip_enable'=>0,'v2board.try_out_plan_id'=>0]);
DB::beginTransaction();
try {
 $plan=Plan::create(['name'=>'QA','group_id'=>1,'transfer_enable'=>10,'month_price'=>100,'show'=>1,'renew'=>1]);
 $user=User::create(['email'=>'redeem@example.invalid','password'=>'unused','uuid'=>'11111111-1111-4111-8111-111111111111','token'=>'qa-only']);
 $make=function($code,$name='dhm QA',$end=null)use($plan){return Coupon::create(['code'=>$code,'name'=>$name,'type'=>2,'value'=>100,'show'=>1,'started_at'=>time()-60,'ended_at'=>$end??time()+3600,'limit_use'=>1,'limit_plan_ids'=>[$plan->id],'limit_period'=>['month_price']]);};
 $coupon=$make('qa-valid');
 $controller=new App\Http\Controllers\V1\User\UserController();
 $response=$controller->redeemPlan(Request::create('/','POST',['redeem_code'=>'qa-valid','user'=>['id'=>$user->id]]));
 $body=json_decode($response->getContent(),true);
 checkRedeem($body['data']['state']===true && $body['data']['msg']==='兑换成功','legacy response contract');
 checkRedeem($user->fresh()->plan_id===$plan->id && $user->fresh()->expired_at>time(),'redemption actually activates plan');
 checkRedeem($coupon->fresh()->limit_use===0 && Order::where('coupon_id',$coupon->id)->where('status',3)->count()===1,'single use consumed and order fulfilled');
 try{$controller->redeemPlan(Request::create('/','POST',['redeem_code'=>'qa-valid','user'=>['id'=>$user->id]]));throw new RuntimeException('reused coupon accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){}
 checkRedeem(Order::where('coupon_id',$coupon->id)->count()===1,'reused coupon rejected without duplicate order');
 foreach([['qa-normal','ordinary',null],['qa-expired','dhm expired',time()-1]] as $args){$c=$make(...$args);try{(new App\Services\LegacyRedemptionService())->redeem($user->id,$c->code);throw new RuntimeException('invalid accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){}checkRedeem($c->fresh()->limit_use===1 && !Order::where('coupon_id',$c->id)->exists(),'invalid coupon rejected without consumption');}
 $auth=new App\Http\Controllers\V1\Passport\AuthController();
 $register=function($email,$code)use($auth){$r=App\Http\Requests\Passport\AuthRegister::create('/api/v1/passport/redeem/register','POST',['email'=>$email,'password'=>'qa-password-123','code'=>$code]);return $auth->register($r);};
 $c=$make('qa-register');$r=$register('newredeem@example.invalid','qa-register');
 checkRedeem($r->getStatusCode()===200 && User::where('email','newredeem@example.invalid')->value('plan_id')===$plan->id,'legacy code registration creates account and activates plan');
 try{$register('failedredeem@example.invalid','qa-nonexistent');throw new RuntimeException('bad registration accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){}
 checkRedeem(!User::where('email','failedredeem@example.invalid')->exists(),'failed redemption registration rolls back user');
 $r=$register('normalregister@example.invalid',null);checkRedeem($r->getStatusCode()===200,'ordinary registration preserved');
 $routes=app('router')->getRoutes();
 foreach(['api/v1/user/redeemPlan','api/v1/passport/redeem/register'] as $path){$route=$routes->match(Request::create('/'.$path,'POST'));checkRedeem(strpos($route->getActionName(),'@')!==false,'route restored '.$path);if(strpos($path,'/user/')!==false)checkRedeem(in_array('user',$route->gatherMiddleware(),true),'user redemption requires authentication');}
} finally { DB::rollBack(); }