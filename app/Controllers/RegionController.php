<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Services\RegionService;

class RegionController
{
    /**
     * Public catalogue of Belarusian regions used by the request form,
     * volunteer feed filters and admin request filters.
     */
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
