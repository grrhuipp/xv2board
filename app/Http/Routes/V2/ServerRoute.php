<?php
namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->any('/config', 'V2\\Server\\ServerController@config');
        });
    }
}
