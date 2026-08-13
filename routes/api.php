<?php

declare(strict_types=1);

use Modules\Shared\Support\ModuleRegistry;

/*
 * Central /api/v1 registration.
 *
 * Every enabled module contributes a Routes/api_v1.php file, loaded here in
 * config/modules.php order (ADR 0001). Routes declared anywhere else are a defect: this
 * file is the one place to see the whole public surface of the API.
 *
 * The `api` middleware group and the `api/v1` prefix are applied by bootstrap/app.php.
 */
foreach (ModuleRegistry::apiV1RouteFiles() as $routeFile) {
    require $routeFile;
}
