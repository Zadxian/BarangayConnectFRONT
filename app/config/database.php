<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

$database['main'] = array(
    'driver'   => 'mysql',
    'hostname' => getenv('DB_HOST') ?: '',
    'username' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'database' => getenv('DB_NAME') ?: '',
    'port'     => getenv('DB_PORT') ?: '',
    'ssl_ca'   => getenv('DB_SSL_CA') ?: '',
    'charset'  => 'utf8mb4',
    'collate'  => 'utf8mb4_unicode_ci',
    'dbprefix' => ''
);
