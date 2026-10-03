<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use QuietLink\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = getenv('APP_ENV');
$kernel = new Kernel(is_string($env) && $env !== '' ? $env : 'prod', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
