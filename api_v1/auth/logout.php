<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

Auth::destroySession();

json_success(null, 'Successfully logged out.');
