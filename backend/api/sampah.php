<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401); // Unauthorized
    echo json_encode(array("message" => "Akses ditolak. Silakan login terlebih dahulu."));
    exit;
}

include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];

$user_id = $_SESSION['user_id'];
$action = $input['action'] ?? $_GET['action'] ?? '';
$id = $input['id'] ?? $_GET['id'] ?? null;

// Validasi user_id
if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Sesi tidak valid atau telah berakhir."]);
    exit;
}

switch ($method) {
    case 'GET':
        getTrashItems($db, $user_id);
        break;
    case 'POST':
        if ($action === 'restore') {
            restoreTrashItem($db, $id, $user_id);
        } elseif ($action === 'emptyTrash') {
            emptyTrash($db, $user_id);
        } elseif ($action === 'cleanupExpired') {
            cleanupExpired($db, $user_id);
        } else {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Aksi POST tidak valid."]);
        }
        break;
    case 'DELETE':
        deleteTrashItem($db, $id, $user_id);
        break;
    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Metode tidak diizinkan."]);
}


function getTrashItems($db, $user_id) {
    try {
        // Cek apakah ada parameter 'search' dan tidak kosong
        $search_term = null;
        if (!empty($_GET['search'])) {
            // Menambahkan wildcard '%' untuk pencarian LIKE
            $search_term = '%' . strtolower($_GET['search']) . '%';
        }

        // Query dasar untuk mengambil item sampah milik user
        $query = "SELECT sampah_id, tipe, dihapus_pada, kadaluarsa_pada, data_asli 
                  FROM sampah 
                  WHERE user_id = :user_id";

        // Tambahkan klausa LIKE jika ada kata kunci pencarian
        if ($search_term !== null) {
            $query .= " AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data_asli, '$.judul'))) LIKE :search";
        }
        
        $query .= " ORDER BY dihapus_pada DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(":user_id", $user_id);

        // Ikat parameter :search jika ada kata kunci pencarian
        if ($search_term !== null) {
            $stmt->bindParam(":search", $search_term);
        }
        
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data_asli = json_decode($row['data_asli'], true);
            $items[] = [
                'id' => $row['sampah_id'],
                'type' => $row['tipe'],
                'title' => $data_asli['judul'] ?? 'Tanpa Judul',
                'deleted_at' => $row['dihapus_pada'],
                'expires_at' => $row['kadaluarsa_pada'],
                'details' => $data_asli
            ];
        }

        // Kembalikan hasil dari query
        echo json_encode(["success" => true, "data" => $items]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}


function restoreTrashItem($db, $sampah_id, $user_id) {
    if (empty($sampah_id) || empty($user_id)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "ID Item dan User ID diperlukan."]);
        return;
    }
    
    $db->beginTransaction();
    
    try {
        $stmt = $db->prepare("SELECT tipe, data_asli FROM sampah WHERE sampah_id = :id AND user_id = :uid");
        $stmt->execute(['id' => $sampah_id, 'uid' => $user_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("Item sampah tidak ditemukan.");
        }

        $tipe = $item['tipe'];
        $data_asli = json_decode($item['data_asli'], true);
        $original_id = $data_asli[$tipe . '_id'] ?? null;

        if (!$original_id) {
            throw new Exception("Original ID tidak ditemukan di data asli.");
        }

        // Membangun query insert secara dinamis berdasarkan tipe
        switch ($tipe) {
            case 'catatan':
                $table = 'catatan';
                $columns = ['catatan_id', 'user_id', 'judul', 'deskripsi', 'kategori', 'dates'];
                $data = [
                    'catatan_id' => $original_id,
                    'user_id' => $user_id,
                    'judul' => $data_asli['judul'],
                    'deskripsi' => $data_asli['deskripsi'],
                    'kategori' => $data_asli['kategori'],
                    'dates' => $data_asli['dates']
                ];
                break;
            case 'tugas':
                $table = 'tugas';
                $columns = ['tugas_id', 'user_id', 'judul', 'deskripsi', 'kategori', 'date_start', 'date_end', 'finish'];
                $data = [
                    'tugas_id' => $original_id,
                    'user_id' => $user_id,
                    'judul' => $data_asli['judul'],
                    'deskripsi' => $data_asli['deskripsi'],
                    'kategori' => $data_asli['kategori'],
                    'date_start' => $data_asli['date_start'],
                    'date_end' => $data_asli['date_end'],
                    'finish' => $data_asli['finish']
                ];
                break;
            case 'tujuan':
                 $table = 'tujuan';
                $columns = ['tujuan_id', 'user_id', 'judul', 'deskripsi', 'kategori', 'date_start', 'date_end', 'finish', 'progres'];
                $data = [
                    'tujuan_id' => $original_id,
                    'user_id' => $user_id,
                    'judul' => $data_asli['judul'],
                    'deskripsi' => $data_asli['deskripsi'],
                    'kategori' => $data_asli['kategori'],
                    'date_start' => $data_asli['date_start'],
                    'date_end' => $data_asli['date_end'],
                    'finish' => $data_asli['finish'],
                    'progres' => $data_asli['progres']
                ];
                break;
            default:
                throw new Exception("Tipe item tidak valid.");
        }

        $cols = implode(", ", $columns);
        $placeholders = ":" . implode(", :", $columns);
        $restore_query = "INSERT INTO $table ($cols) VALUES ($placeholders)";
        $restore_stmt = $db->prepare($restore_query);
        
        if (!$restore_stmt->execute($data)) {
            throw new Exception("Gagal memulihkan data ke tabel asli.");
        }

        // Pulihkan subtujuan jika ada
        if ($tipe === 'tujuan' && !empty($data_asli['subtujuan'])) {
            foreach ($data_asli['subtujuan'] as $sub) {
                $sub_query = "INSERT INTO subtujuan (subtujuan_id, tujuan_id, judul, prioritas, date_end, finish) 
                             VALUES (:subtujuan_id, :tujuan_id, :judul, :prioritas, :date_end, :finish)";
                $sub_stmt = $db->prepare($sub_query);
                if (!$sub_stmt->execute([
                    ':subtujuan_id' => $sub['subtujuan_id'],
                    ':tujuan_id'    => $original_id,
                    ':judul'        => $sub['judul'],
                    ':prioritas'    => $sub['prioritas'],
                    ':date_end'     => $sub['date_end'],
                    ':finish'       => $sub['finish']
                ])) {
                    throw new Exception("Gagal memulihkan subtujuan.");
                }
            }
        }
        
        // Hapus dari sampah
        $delete_stmt = $db->prepare("DELETE FROM sampah WHERE sampah_id = :id");
        if (!$delete_stmt->execute(['id' => $sampah_id])) {
            throw new Exception("Gagal menghapus data dari sampah setelah dipulihkan.");
        }
        
        $db->commit();
        echo json_encode(["success" => true, "message" => "Item berhasil dipulihkan."]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Gagal memulihkan item: " . $e->getMessage()]);
    }
}

function deleteTrashItem($db, $sampah_id, $user_id) {
    try {
        if (empty($sampah_id) || empty($user_id)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "ID Item atau User ID diperlukan."]);
            return;
        }

        $stmt = $db->prepare("DELETE FROM sampah WHERE sampah_id = :id AND user_id = :uid");
        $stmt->execute(['id' => $sampah_id, 'uid' => $user_id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(["success" => true, "message" => "Item dihapus permanen."]);
        } else {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Item sampah tidak ditemukan."]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}

function emptyTrash($db, $user_id) {
    try {
        $stmt = $db->prepare("DELETE FROM sampah WHERE user_id = :uid");
        $stmt->execute(['uid' => $user_id]);
        echo json_encode(["success" => true, "message" => "Sampah berhasil dikosongkan."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}

function cleanupExpired($db, $user_id) {
    try {
        $stmt = $db->prepare("DELETE FROM sampah WHERE user_id = :uid AND kadaluarsa_pada <= CURDATE()");
        $stmt->execute(['uid' => $user_id]);
        echo json_encode(["success" => true, "message" => "Item kedaluwarsa berhasil dihapus."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
?>
