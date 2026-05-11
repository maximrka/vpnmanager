<?php

declare(strict_types=1);

use App\App;

require_once __DIR__ . '/../src/bootstrap.php';

$app = new App();
$app->run();
