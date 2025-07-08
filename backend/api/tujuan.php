<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Cek apakah pengguna sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401); // Unauthorized
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

// Fungsi untuk membuat subtujuan
function createSubtujuan($db, $tujuan_id, $task) {
    $query = "INSERT INTO subtujuan (tujuan_id, judul, prioritas, date_end, finish) VALUES (:tujuan_id, :judul, :prioritas, :date_end, :finish)";
    $stmt = $db->prepare($query);
    
    $title = $task['title'] ?? 'Tanpa Judul';
    $priority = strtolower($task['priority'] ?? 'medium');
    $deadline = $task['deadline'] ?? null;
    $completed = !empty($task['completed']);

    $stmt->bindParam(':tujuan_id', $tujuan_id);
    $stmt->bindParam(':judul', $title);
    $stmt->bindParam(':prioritas', $priority);
    $stmt->bindParam(':date_end', $deadline);
    $stmt->bindParam(':finish', $completed, PDO::PARAM_BOOL);
    
    $stmt->execute();
}


switch($method) {
    case 'GET':
        // Pastikan user_id dilewatkan ke fungsi
        getTujuan($db, $user_id);
        break;
    case 'POST':
        // Pastikan user_id dilewatkan ke fungsi
        createTujuan($db, $input, $user_id);
        break;
    case 'PUT':
        // Pastikan user_id dilewatkan ke fungsi
        updateTujuan($db, $input, $user_id);
        break;
    case 'DELETE':
        // Pastikan user_id dilewatkan ke fungsi
        deleteTujuan($db, $input, $user_id);
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method not allowed"));
}

function getTujuan($db, $user_id) {
    try {
        // Cek apakah ada parameter 'search' dan tidak kosong
        $search_term = null;
        if (!empty($_GET['search'])) {
            $search_term = '%' . $_GET['search'] . '%';
        }

        // Query dasar
        $query = "SELECT t.tujuan_id, t.judul, t.deskripsi, t.kategori, t.date_start, t.date_end, t.progres, t.finish,
                  (SELECT COUNT(*) FROM subtujuan s WHERE s.tujuan_id = t.tujuan_id) as total_subtujuan,
                  (SELECT COUNT(*) FROM subtujuan s WHERE s.tujuan_id = t.tujuan_id AND s.finish = 1) as completed_subtujuan
                  FROM tujuan t 
                  WHERE t.user_id = :user_id";

        // HANYA tambahkan klausa LIKE jika ada kata kunci pencarian
        if ($search_term !== null) {
            $query .= " AND t.judul LIKE :search";
        }
        
        $query .= " ORDER BY t.date_start DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(":user_id", $user_id);

        // HANYA ikat parameter :search jika ada kata kunci pencarian
        if ($search_term !== null) {
            $stmt->bindParam(":search", $search_term);
        }
        
        $stmt->execute();
        
        $tujuan_list = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $progress = 0;
            if ($row['finish']) {
                $progress = 100;
            } elseif ($row['total_subtujuan'] > 0) {
                $progress = round(($row['completed_subtujuan'] / $row['total_subtujuan']) * 100);
            } else {
                $progress = $row['progres'];
            }
            
            $sub_query = "SELECT subtujuan_id, judul, prioritas, date_end, finish FROM subtujuan WHERE tujuan_id = :tujuan_id ORDER BY date_end ASC";
            $sub_stmt = $db->prepare($sub_query);
            $sub_stmt->bindParam(":tujuan_id", $row['tujuan_id']);
            $sub_stmt->execute();
            $subtujuan = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $tujuan_item = array(
                "id" => $row['tujuan_id'],
                "title" => $row['judul'],
                "description" => $row['deskripsi'],
                "category" => ucfirst($row['kategori']),
                "startDate" => $row['date_start'],
                "targetDate" => $row['date_end'],
                "progress" => (int)$progress,
                "completed" => (bool)$row['finish'],
                "tasks" => array_map(function($sub) {
                    return array(
                        "id" => $sub['subtujuan_id'],
                        "title" => $sub['judul'],
                        "priority" => ucfirst($sub['prioritas']),
                        "deadline" => $sub['date_end'],
                        "completed" => (bool)$sub['finish']
                    );
                }, $subtujuan)
            );
            $tujuan_list[] = $tujuan_item;
        }
        echo json_encode($tujuan_list);

    } catch(Exception $e) {
        http_response_code(500);
        // Mengembalikan pesan error SQL yang lebih detail untuk debugging
        echo json_encode(array("success" => false, "message" => "Error: " . $e->getMessage()));
    }
}

function createTujuan($db, $data, $user_id) {
    $db->beginTransaction();
    try {
        $query = "INSERT INTO tujuan (user_id, judul, deskripsi, kategori, date_start, date_end, finish, progres) 
                  VALUES (:user_id, :judul, :deskripsi, :kategori, :date_start, :date_end, :finish, :progres)";
        $stmt = $db->prepare($query);

        $title = $data['title'] ?? 'Tanpa Judul';
        $description = $data['description'] ?? '';
        $category = strtolower($data['category'] ?? 'lainnya');
        $startDate = $data['startDate'] ?? null;
        $targetDate = $data['targetDate'] ?? null;
        $completed = !empty($data['completed']);
        $progress = $data['progress'] ?? 0;

        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":judul", $title);
        $stmt->bindParam(":deskripsi", $description);
        $stmt->bindParam(":kategori", $category);
        $stmt->bindParam(":date_start", $startDate);
        $stmt->bindParam(":date_end", $targetDate);
        $stmt->bindParam(":finish", $completed, PDO::PARAM_BOOL);
        $stmt->bindParam(":progres", $progress);
        
        $stmt->execute();
        $tujuan_id = $db->lastInsertId();

        if (isset($data['tasks']) && is_array($data['tasks'])) {
            foreach($data['tasks'] as $task) {
                createSubtujuan($db, $tujuan_id, $task);
            }
        }
        
        $db->commit();
        echo json_encode(["success" => true, "message" => "Tujuan berhasil dibuat", "id" => $tujuan_id]);
    } catch(Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}


function updateTujuan($db, $data, $user_id) {
    $db->beginTransaction();

    try {
        // Validate input data
        if (!isset($data['id']) || !is_numeric($data['id'])) {
            throw new Exception("Invalid or missing tujuan_id");
        }
        $tujuan_id = $data['id'];

        // Check if tujuan exists and belongs to the user
        $check_query = "SELECT tujuan_id FROM tujuan WHERE tujuan_id = :tujuan_id AND user_id = :user_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(":tujuan_id", $tujuan_id);
        $check_stmt->bindParam(":user_id", $user_id);
        $check_stmt->execute();

        if ($check_stmt->rowCount() == 0) {
            throw new Exception("Tujuan not found or not owned by user");
        }

        // Update main tujuan
        $query_tujuan = "UPDATE tujuan SET judul = :judul, deskripsi = :deskripsi, kategori = :kategori, 
                         date_start = :date_start, date_end = :date_end, finish = :finish, progres = :progres
                         WHERE tujuan_id = :tujuan_id AND user_id = :user_id";
        
        $stmt_tujuan = $db->prepare($query_tujuan);

        $title = $data['title'] ?? 'Tanpa Judul';
        $description = $data['description'] ?? '';
        $category = strtolower($data['category'] ?? 'lainnya');
        $startDate = $data['startDate'] ?? null;
        $targetDate = $data['targetDate'] ?? null;
        $completed = !empty($data['completed']) ? 1 : 0;
        $progress = isset($data['progress']) ? (int)$data['progress'] : 0;

        if (!$startDate || !$targetDate) {
            throw new Exception("Start date and target date are required");
        }
        if (strtotime($targetDate) < strtotime($startDate)) {
            throw new Exception("Target date cannot be earlier than start date");
        }

        $stmt_tujuan->bindParam(":tujuan_id", $tujuan_id);
        $stmt_tujuan->bindParam(":user_id", $user_id);
        $stmt_tujuan->bindParam(":judul", $title);
        $stmt_tujuan->bindParam(":deskripsi", $description);
        $stmt_tujuan->bindParam(":kategori", $category);
        $stmt_tujuan->bindParam(":date_start", $startDate);
        $stmt_tujuan->bindParam(":date_end", $targetDate);
        $stmt_tujuan->bindParam(":finish", $completed, PDO::PARAM_BOOL);
        $stmt_tujuan->bindParam(":progres", $progress);

        $stmt_tujuan->execute();

        // Synchronize subtasks
        if (isset($data['tasks']) && is_array($data['tasks'])) {
            // Get existing subtask IDs
            $stmt_db_ids = $db->prepare("SELECT subtujuan_id FROM subtujuan WHERE tujuan_id = :tujuan_id");
            $stmt_db_ids->bindParam(':tujuan_id', $tujuan_id);
            $stmt_db_ids->execute();
            $db_subtujuan_ids = $stmt_db_ids->fetchAll(PDO::FETCH_COLUMN, 0);

            $frontend_subtujuan_ids = [];
            foreach ($data['tasks'] as $task) {
                if (isset($task['id']) && is_numeric($task['id'])) {
                    $frontend_subtujuan_ids[] = $task['id'];
                }
            }

            // Delete subtasks that are in DB but not in frontend
            $ids_to_delete = array_diff($db_subtujuan_ids, $frontend_subtujuan_ids);
            if (!empty($ids_to_delete)) {
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $stmt_delete = $db->prepare("DELETE FROM subtujuan WHERE subtujuan_id IN ($placeholders)");
                $stmt_delete->execute(array_values($ids_to_delete));
            }

            // Update or insert subtasks
            foreach ($data['tasks'] as $task) {
                // Validate task data
                if (empty($task['title'])) {
                    throw new Exception("Task title is required");
                }
                if (empty($task['deadline'])) {
                    throw new Exception("Task deadline is required for task: " . $task['title']);
                }
                if (strtotime($task['deadline']) < strtotime($startDate)) {
                    throw new Exception("Task deadline cannot be earlier than goal start date for task: " . $task['title']);
                }

                $sub_completed = !empty($task['completed']) ? 1 : 0;
                $sub_priority = strtolower($task['priority'] ?? 'medium');
                $sub_deadline = $task['deadline'];
                $sub_title = $task['title'];

                // Update existing subtask
                if (isset($task['id']) && in_array($task['id'], $db_subtujuan_ids)) {
                    $stmt_update = $db->prepare(
                        "UPDATE subtujuan SET judul = :judul, prioritas = :prioritas, date_end = :date_end, finish = :finish 
                         WHERE subtujuan_id = :subtujuan_id"
                    );
                    $stmt_update->execute([
                        ':judul' => $sub_title,
                        ':prioritas' => $sub_priority,
                        ':date_end' => $sub_deadline,
                        ':finish' => $sub_completed,
                        ':subtujuan_id' => $task['id']
                    ]);
                } else {
                    // Insert new subtask
                    $stmt_insert = $db->prepare(
                        "INSERT INTO subtujuan (tujuan_id, judul, prioritas, date_end, finish) 
                         VALUES (:tujuan_id, :judul, :prioritas, :date_end, :finish)"
                    );
                    $stmt_insert->execute([
                        ':tujuan_id' => $tujuan_id,
                        ':judul' => $sub_title,
                        ':prioritas' => $sub_priority,
                        ':date_end' => $sub_deadline,
                        ':finish' => $sub_completed
                    ]);
                }
            }
        } else {
            // If no tasks are provided, delete all subtasks
            $stmt_delete_all = $db->prepare("DELETE FROM subtujuan WHERE tujuan_id = :tujuan_id");
            $stmt_delete_all->execute([':tujuan_id' => $tujuan_id]);
        }

        $db->commit();
        echo json_encode(["success" => true, "message" => "Tujuan berhasil diupdate"]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}


function deleteTujuan($db, $data, $user_id) {
    $db->beginTransaction();
    try {
        $tujuan_id = $data['id'];

        $select_query = "SELECT * FROM tujuan WHERE tujuan_id = :tujuan_id AND user_id = :user_id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(":tujuan_id", $tujuan_id);
        $select_stmt->bindParam(":user_id", $user_id);
        $select_stmt->execute();

        if($select_stmt->rowCount() > 0) {
            $tujuan = $select_stmt->fetch(PDO::FETCH_ASSOC);

            $sub_query = "SELECT * FROM subtujuan WHERE tujuan_id = :tujuan_id";
            $sub_stmt = $db->prepare($sub_query);
            $sub_stmt->bindParam(":tujuan_id", $tujuan_id);
            $sub_stmt->execute();
            $subtujuan_data = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

            $data_asli = [
                'tujuan_id' => $tujuan['tujuan_id'],
                'judul' => $tujuan['judul'],
                'deskripsi' => $tujuan['deskripsi'],
                'kategori' => $tujuan['kategori'],
                'date_start' => $tujuan['date_start'],
                'date_end' => $tujuan['date_end'],
                'finish' => $tujuan['finish'],
                'progres' => $tujuan['progres'],
                'subtujuan' => $subtujuan_data
            ];

            $sampah_query = "INSERT INTO Sampah (user_id, tujuan_id, tipe, dihapus_pada, kadaluarsa_pada, data_asli)
                            VALUES (:user_id, :tujuan_id, 'tujuan', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), :data_asli)";
            $sampah_stmt = $db->prepare($sampah_query);
            $sampah_stmt->bindParam(":user_id", $user_id);
            $sampah_stmt->bindParam(":tujuan_id", $tujuan_id);
            $sampah_stmt->bindValue(":data_asli", json_encode($data_asli));
            $sampah_stmt->execute();

            $delete_query = "DELETE FROM tujuan WHERE tujuan_id = :tujuan_id AND user_id = :user_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(":tujuan_id", $tujuan_id);
            $delete_stmt->bindParam(":user_id", $user_id);

            if($delete_stmt->execute()) {
                $db->commit();
                echo json_encode(["success" => true, "message" => "Tujuan berhasil dihapus"]);
            } else {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Gagal menghapus tujuan"]);
            }
        } else {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Tujuan tidak ditemukan"]);
        }
    } catch(Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
?>