<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Services\RegionService;

class RegionController
{




    public function index(): void
    {
        $regions = RegionService::all();
        Router::json([
            'success' => true,
            'count' => count($regions),
            'data' => $regions
        ]);
    }
}
