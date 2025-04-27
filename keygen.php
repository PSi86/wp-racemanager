<?php
// generate_vapid_keys.php

// Make sure you have installed the Minishlink/WebPush library via Composer:
//composer require minishlink/web-push

//require_once __DIR__ . '/vendor/autoload.php';
//require_once '/vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/autoload.php'; // Relative path to the vendor directory (currently in root of httpdocs)
//require_once dirname(ABSPATH) . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

// Generate the VAPID keys using the built-in function
$vapidKeys = VAPID::createVapidKeys();

echo "Public VAPID Key: " . $vapidKeys['publicKey'] . "<br>";
echo "Private VAPID Key: " . $vapidKeys['privateKey'];
