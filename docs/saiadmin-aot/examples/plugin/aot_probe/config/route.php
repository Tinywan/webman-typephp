<?php

declare(strict_types=1);

use plugin\aot_probe\app\controller\ProbeController;
use Webman\Route;

Route::get('/aot-probe', [ProbeController::class, 'index']);
