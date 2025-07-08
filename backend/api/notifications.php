<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(["message" => "Akses ditolak."]);
    exit;
}

include_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

function getAllNotifications($db, $user_id) {
    $notifications = [];
    $today = date('Y-m-d');
    $three_days_later = date('Y-m-d', strtotime('+3 days'));

    // --- TUGAS ---
    $query_tugas = "SELECT tugas_id, judul, date_end, finish FROM tugas WHERE user_id = :user_id AND finish = 0";
    $stmt_tugas = $db->prepare($query_tugas);
    $stmt_tugas->execute(['user_id' => $user_id]);
    while ($row = $stmt_tugas->fetch(PDO::FETCH_ASSOC)) {
        if ($row['date_end'] < $today) {
            $notifications[] = ['type' => 'Tugas', 'id' => $row['tugas_id'], 'title' => $row['judul'], 'message' => 'Tugas ini telah melewati tenggat waktu.', 'due_date' => $row['date_end'], 'status' => 'terlambat'];
        } elseif ($row['date_end'] <= $three_days_later) {
            $notifications[] = ['type' => 'Tugas', 'id' => $row['tugas_id'], 'title' => $row['judul'], 'message' => 'Tenggat waktu tugas ini semakin dekat.', 'due_date' => $row['date_end'], 'status' => 'mendekat'];
        }
    }

    // --- TUJUAN ---
    $query_tujuan = "SELECT tujuan_id, judul, date_end, finish FROM tujuan WHERE user_id = :user_id AND finish = 0";
    $stmt_tujuan = $db->prepare($query_tujuan);
    $stmt_tujuan->execute(['user_id' => $user_id]);
    while ($row = $stmt_tujuan->fetch(PDO::FETCH_ASSOC)) {
        if ($row['date_end'] < $today) {
            $notifications[] = ['type' => 'Tujuan', 'id' => $row['tujuan_id'], 'title' => $row['judul'], 'message' => 'Tujuan ini telah melewati target selesai.', 'due_date' => $row['date_end'], 'status' => 'terlambat'];
        } elseif ($row['date_end'] <= $three_days_later) {
            $notifications[] = ['type' => 'Tujuan', 'id' => $row['tujuan_id'], 'title' => $row['judul'], 'message' => 'Target untuk tujuan ini akan segera berakhir.', 'due_date' => $row['date_end'], 'status' => 'mendekat'];
        }
    }

    // --- SUBTUJUAN ---
    $query_sub = "SELECT s.subtujuan_id, t.tujuan_id, s.judul, s.date_end, t.judul as parent_title FROM subtujuan s JOIN tujuan t ON s.tujuan_id = t.tujuan_id WHERE t.user_id = :user_id AND s.finish = 0";
    $stmt_sub = $db->prepare($query_sub);
    $stmt_sub->execute(['user_id' => $user_id]);
    while ($row = $stmt_sub->fetch(PDO::FETCH_ASSOC)) {
        if ($row['date_end'] < $today) {
            $notifications[] = ['type' => 'Subtujuan', 'id' => $row['subtujuan_id'], 'tujuan_id' => $row['tujuan_id'], 'title' => $row['judul'], 'message' => 'Subtujuan dari "' . $row['parent_title'] . '" telah terlambat.', 'due_date' => $row['date_end'], 'status' => 'terlambat'];
        } elseif ($row['date_end'] <= $three_days_later) {
            $notifications[] = ['type' => 'Subtujuan', 'id' => $row['subtujuan_id'], 'tujuan_id' => $row['tujuan_id'], 'title' => $row['judul'], 'message' => 'Subtujuan dari "' . $row['parent_title'] . '" akan segera berakhir.', 'due_date' => $row['date_end'], 'status' => 'mendekat'];
        }
    }

    return $notifications;
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $notifications = getAllNotifications($db, $user_id);
    echo json_encode(['success' => true, 'data' => $notifications]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
}
?>