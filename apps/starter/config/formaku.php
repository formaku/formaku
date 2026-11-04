<?php

use App\Support\FormakuAccess;

return ['public_routes' => true, 'authorize' => [FormakuAccess::class, 'allows'], 'staff' => [FormakuAccess::class, 'staff']];
