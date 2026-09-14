<?php

declare(strict_types=1);

namespace plugin\aot_probe\app\controller;

use support\Request;
use support\Response;

final class ProbeController
{
    public function index(Request $request): Response
    {
        return json(['code' => 200, 'data' => ['aot' => true]]);
    }
}
