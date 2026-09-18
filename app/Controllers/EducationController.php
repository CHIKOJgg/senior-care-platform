<?php

namespace App\Controllers;

use App\Core\Router;
use App\Models\EducationalMaterial;

class EducationController
{
    public function index(): void
    {
        $materials = EducationalMaterial::all();
        Router::json([
            'success' => true,
            'data' => $materials
        ]);
    }
}
