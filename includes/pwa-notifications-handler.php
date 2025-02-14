<?php
// includes/pwa-notifications-handler.php
// Send web push notifications to subscribers when a pilot is in the lineup for the next race

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Load the WebPush library
//require __DIR__ . '/vendor/autoload.php';
require_once dirname(ABSPATH) . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// VAPID authentication details – replace these with your own keys and contact
$vapid = [
    'subject' => 'mailto:',  // Can be a mailto: or your website address
    'publicKey' => '',  // Replace with your public key
    'privateKey' => '' // Replace with your private key
];

// Determine which pilot is in the lineup for the next race
// (This value might come from your race logic; here we hard-code for demonstration)
$targetPilot = 'pilot1';

// Create the notification payload
$notificationPayload = json_encode([
    'title' => 'Race Alert!',
    'body'  => 'Your selected pilot is in the lineup for the next race!',
    'icon'  => '/icon-192.png',
    'url'   => '/next-up' // race-info //URL to navigate when the user clicks the notification
]);

// Database credentials – update these with your own values
$db_host = "localhost";
$db_name = "your_database_name";
$db_user = "your_db_username";
$db_pass = "your_db_password";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Retrieve all subscriptions for the target pilot
$stmt = $pdo->prepare("SELECT subscription FROM push_subscriptions WHERE pilot = :pilot");
$stmt->execute([':pilot' => $targetPilot]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize WebPush
$webPush = new WebPush($vapid);

// Queue notifications for each subscription
foreach ($subscriptions as $sub) {
    $subscriptionData = json_decode($sub['subscription'], true);
    if ($subscriptionData) {
        $subscription = Subscription::create($subscriptionData);
        $webPush->queueNotification($subscription, $notificationPayload);
    }
}

// Send out the notifications and report results
$report = $webPush->flush();
foreach ($report as $result) {
    $endpoint = $result->getRequest()->getUri()->__toString();
    if ($result->isSuccess()) {
        echo "Notification sent successfully to {$endpoint}." . PHP_EOL;
    } else {
        echo "Notification failed for {$endpoint}: " . $result->getReason() . PHP_EOL;
    }
}
?>
