<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConnectionAnalysisService;
use Illuminate\Http\Request;

class ConnectionAnalysisController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'q' => 'nullable|string|max:100', 'days' => 'sometimes|integer|in:0,1,3,7,30',
            'event' => 'sometimes|in:all,multi_ip,geo',
            'page' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:50',
        ]);
        return response()->json((new ConnectionAnalysisService())->fetch($params))->header('Cache-Control', 'private, no-store');
    }
}
