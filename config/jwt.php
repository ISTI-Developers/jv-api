<?php

require_once __DIR__ . '/env.php';

return [
    'secret' => $_ENV['JWT_SECRET'],
    'algo'   => 'HS256',
    'expiry' => 60 * 60 * 24
];
