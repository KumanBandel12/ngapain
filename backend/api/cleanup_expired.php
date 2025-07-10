<?php
include_once 'config/database.php';
include_once 'api/sampah.php';

$database = new Database();
$db = $database->getConnection();

// Ambil semua user_id
$stmt = $db->query("SELECT DISTINCT user_id FROM sampah");
$user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($user_ids as $user_id) {
    cleanupExpired($db, $user_id);
}

echo json_encode(["success" => true, "message" => "Pembersihan item kedaluwarsa selesai."]);
?>