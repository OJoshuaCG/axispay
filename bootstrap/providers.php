<?php

declare(strict_types=1);

use App\Modules\Shared\Providers\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
];
