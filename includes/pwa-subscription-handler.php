<?php
// includes/pwa-subscription-handler.php
// Endpoint for saving a new push subscription

header("Content-Type: application/json");

// Retrieve the JSON input
$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['pilot']) || !isset($data['subscription'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid subscription data"]);
    exit;
}

$pilot = $data['pilot'];
$subscription = json_encode($data['subscription']); // Store as JSON

// Database credentials – update these with your own values
$db_host = "localhost";
$db_name = "your_database_name";
$db_user = "your_db_username";
$db_pass = "your_db_password";

try {
    // Create a PDO connection
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

// Create the subscriptions table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pilot VARCHAR(100) NOT NULL,
    subscription JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4";
$pdo->exec($createTableQuery);

// Insert the new subscription
$stmt = $pdo->prepare("INSERT INTO push_subscriptions (pilot, subscription) VALUES (:pilot, :subscription)");
$result = $stmt->execute([
    ':pilot' => $pilot,
    ':subscription' => $subscription
]);

if ($result) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to save subscription"]);
}
?>
