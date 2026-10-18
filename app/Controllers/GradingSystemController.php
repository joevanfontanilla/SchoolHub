<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\GradeCalculator;

/**
 * The school's Official Grading System, read-only, for every signed-in role (docs/27).
 */
final class GradingSystemController extends Controller
{
    public function show(Request $request): void
    {
        $this->appView('grading-system/index', [
            'title' => 'Official Grading System',
            'table' => GradeCalculator::TABLE,
        ]);
    }
}