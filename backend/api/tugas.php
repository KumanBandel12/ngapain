<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(array("message" => "Akses ditolak. Silakan login terlebih dahulu."));
    exit;
}

include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$user_id = $_SESSION['user_id'];

if(!$user_id) {
    http_response_code(401);
    echo json_encode(array("message" => "User ID required"));
    exit;
}

switch($method) {
    case 'GET':
        getTugas($db, $user_id);
        break;
    case 'POST':
        createTugas($db, $input, $user_id);
        break;
    case 'PUT':
        updateTugas($db, $input, $user_id);
        break;
    case 'DELETE':
        deleteTugas($db, $input, $user_id);
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method not allowed"));
}

function getTugas($db, $user_id) {
    try {
        $search_term = !empty($_GET['search']) ? '%' . $_GET['search'] . '%' : null;
        $exclude_overdue = isset($_GET['exclude_overdue']) && $_GET['exclude_overdue'] === 'true';

        $sortBy = $_GET['sortBy'] ?? 'date_start';
        $sortOrder = $_GET['sortOrder'] ?? 'DESC';

        $allowedSortBy = ['date_start', 'date_end', 'judul'];
        if (!in_array($sortBy, $allowedSortBy)) {
            $sortBy = 'date_start';
        }

        $allowedSortOrder = ['ASC', 'DESC'];
        if (!in_array(strtoupper($sortOrder), $allowedSortOrder)) {
            $sortOrder = 'DESC';
        }

        $query = "SELECT * FROM tugas WHERE user_id = :user_id";

        if ($search_term) {
            $query .= " AND judul LIKE :search";
        }

        if ($exclude_overdue) {
            $query .= " AND date_end >= CURDATE()";
        }

        $query .= " ORDER BY " . $sortBy . " " . $sortOrder;

        $stmt = $db->prepare($query);
        $stmt->bindParam(":user_id", $user_id);

        if ($search_term) {
            $stmt->bindParam(":search", $search_term);
        }

        $stmt->execute();

        $tugas_list = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tugas_item = array(
                "id" => $row['tugas_id'],
                "title" => $row['judul'],
                "description" => $row['deskripsi'],
                "category" => ucfirst($row['kategori']),
                "startDate" => $row['date_start'],
                "targetDate" => $row['date_end'],
                "completed" => (bool)$row['finish']
            );
            $tugas_list[] = $tugas_item;
        }

        echo json_encode($tugas_list);
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(array("message" => "Error: " . $e->getMessage()));
    }
}

function createTugas($db, $data, $user_id) {
    try {
        $query = "INSERT INTO tugas (user_id, judul, deskripsi, kategori, date_start, date_end) 
                  VALUES (:user_id, :judul, :deskripsi, :kategori, :date_start, :date_end)";
        
        $stmt = $db->prepare($query);

        $title = $data['title'] ?? 'Tanpa Judul';
        $description = $data['description'] ?? '';
        $category = strtolower($data['category'] ?? 'lainnya');
        $startDate = $data['startDate'] ?? null;
        $targetDate = $data['targetDate'] ?? null;

        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":judul", $title);
        $stmt->bindParam(":deskripsi", $description);
        $stmt->bindParam(":kategori", $category);
        $stmt->bindParam(":date_start", $startDate);
        $stmt->bindParam(":date_end", $targetDate);
        
        if($stmt->execute()) {
            echo json_encode(array("success" => true, "message" => "Tugas berhasil dibuat"));
        } else {
            http_response_code(500);
            echo json_encode(array("success" => false, "message" => "Gagal membuat tugas"));
        }
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Error: " . $e->getMessage()));
    }
}

function updateTugas($db, $data, $user_id) {
    try {
        $query = "UPDATE tugas SET judul = :judul, deskripsi = :deskripsi, kategori = :kategori, 
                  date_start = :date_start, date_end = :date_end, finish = :finish
                  WHERE tugas_id = :tugas_id AND user_id = :user_id";
        
        $stmt = $db->prepare($query);
        
        $id = $data['id'] ?? 0;
        $title = $data['title'] ?? 'Tanpa Judul';
        $description = $data['description'] ?? '';
        $category = strtolower($data['category'] ?? 'lainnya');
        $startDate = $data['startDate'] ?? null;
        $targetDate = $data['targetDate'] ?? null;
        $completed = !empty($data['completed']); 
        
        $stmt->bindParam(":tugas_id", $id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":judul", $title);
        $stmt->bindParam(":deskripsi", $description);
        $stmt->bindParam(":kategori", $category);
        $stmt->bindParam(":date_start", $startDate);
        $stmt->bindParam(":date_end", $targetDate);
        $stmt->bindParam(":finish", $completed, PDO::PARAM_BOOL); 
        
        if($stmt->execute()) {
            echo json_encode(array("success" => true, "message" => "Tugas berhasil diupdate"));
        } else {
            http_response_code(500);
            echo json_encode(array("success" => false, "message" => "Gagal mengupdate tugas"));
        }
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Error: " . $e->getMessage()));
    }
}

function deleteTugas($db, $data, $user_id) {
    $db->beginTransaction();
    try {
        $select_query = "SELECT * FROM tugas WHERE tugas_id = :tugas_id AND user_id = :user_id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(":tugas_id", $data['id']);
        $select_stmt->bindParam(":user_id", $user_id);
        $select_stmt->execute();
        
        if($select_stmt->rowCount() > 0) {
            $tugas = $select_stmt->fetch(PDO::FETCH_ASSOC);
            
            $data_asli = [
                'tugas_id' => $tugas['tugas_id'],
                'judul' => $tugas['judul'],
                'deskripsi' => $tugas['deskripsi'],
                'kategori' => $tugas['kategori'],
                'date_start' => $tugas['date_start'],
                'date_end' => $tugas['date_end'],
                'finish' => $tugas['finish']
            ];
            
            $sampah_query = "INSERT INTO sampah (user_id, tugas_id, tipe, dihapus_pada, kadaluarsa_pada, data_asli) 
                            VALUES (:user_id, :tugas_id, 'tugas', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), :data_asli)";
            $sampah_stmt = $db->prepare($sampah_query);
            $sampah_stmt->bindParam(":user_id", $user_id);
            $sampah_stmt->bindParam(":tugas_id", $data['id']);
            $sampah_stmt->bindValue(":data_asli", json_encode($data_asli));
            $sampah_stmt->execute();
            
            $delete_query = "DELETE FROM tugas WHERE tugas_id = :tugas_id AND user_id = :user_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(":tugas_id", $data['id']);
            $delete_stmt->bindParam(":user_id", $user_id);
            
            if($delete_stmt->execute()) {
                $db->commit();
                echo json_encode(["success" => true, "message" => "Tugas berhasil dihapus"]);
            } else {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Gagal menghapus tugas"]);
            }
        } else {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Tugas tidak ditemukan"]);
        }
    } catch(Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
?>