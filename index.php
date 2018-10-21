<?php

use App\App;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

error_reporting(E_ALL);
ini_set('display_errors', true);

$app = new App();
$app->run();

