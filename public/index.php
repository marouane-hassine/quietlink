<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use QuietLink\Kernel;
use QuietLink\Runtime\Environment;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$environment = Environment::fromVariables(Environment::processVariables());
$kernel = new Kernel($environment->name, $environment->debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
