<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Pastikan path ke database.php sudah benar
include_once '../config/database.php'; 

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'POST':
        if(isset($input['action'])) {
            switch($input['action']) {
                case 'register':
                    register($db, $input);
                    break;
                case 'login':
                    login($db, $input);
                    break;
                case 'logout':
                    logout();
                    break;
                case 'delete_account':
                    delete_account($db, $input);
                    break;
                case 'test':
                    http_response_code(200);
                    echo json_encode(array("success" => true, "message" => "API is responding"));
                    break;
                case 'request_password_reset':
                    request_password_reset($db, $input);
                    break;
                case 'reset_password':
                    reset_password($db, $input);
                    break;
                case 'update_theme':
                    update_theme($db, $input);
                    break;
                case 'change_password':
                    // Memastikan pengguna sudah login sebelum mengganti password
                    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                        http_response_code(401);
                        echo json_encode(["success" => false, "message" => "Akses ditolak, silakan login terlebih dahulu."]);
                        exit;
                    }
                    change_password($db, $input, $_SESSION['user_id']);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(array("success" => false, "message" => "Invalid action"));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Action is not specified"));
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method not allowed"));
}

function register($db, $data) {
    try {
        if(empty($data['nama']) || empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Semua field harus diisi"));
            return;
        }

        $password = $data['password'];
        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Kata sandi minimal harus 8 karakter."));
            return;
        }

        if (!preg_match('/[0-9]/', $password)) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Kata sandi harus mengandung minimal satu angka."));
            return;
        }

        $check_query = "SELECT user_id FROM users WHERE email = :email AND delete_at IS NULL";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(":email", $data['email']);
        $check_stmt->execute();

        if($check_stmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Email sudah terdaftar"));
            return;
        }

        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

        $query = "INSERT INTO users (nama, email, password, create_at) VALUES (:nama, :email, :password, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":nama", $data['nama']);
        $stmt->bindParam(":email", $data['email']);
        $stmt->bindParam(":password", $hashed_password);

        if($stmt->execute()) {
            $user_id = $db->lastInsertId();
            echo json_encode(array(
                "success" => true, 
                "message" => "Registrasi berhasil",
                "user_id" => $user_id
            ));
        } else {
            http_response_code(500);
            echo json_encode(array("success" => false, "message" => "Gagal mendaftar"));
        }
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Error: " . $e->getMessage()));
    }
}

function login($db, $data) {
    try {
        if(empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Email dan password harus diisi"));
            return;
        }

        $query = "SELECT user_id, nama, email, password, theme FROM users WHERE email = :email AND delete_at IS NULL";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":email", $data['email']);
        $stmt->execute();

        if($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(password_verify($data['password'], $user['password'])) {
                // Hapus sesi lama untuk mencegah session fixation
                session_regenerate_id(true);

                // Simpan informasi pengguna ke dalam session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['nama'] = $user['nama'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['logged_in'] = true;

                $update_query = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(":user_id", $user['user_id']);
                $update_stmt->execute();
                
                echo json_encode(array(
                    "success" => true,
                    "message" => "Login berhasil",
                    "user" => array(
                        "user_id" => $user['user_id'],
                        "nama" => $user['nama'],
                        "email" => $user['email'],
                        "theme" => $user['theme']
                    )
                    // Token tidak lagi diperlukan untuk pendekatan sesi
                ));
            } else {
                http_response_code(401);
                echo json_encode(array("success" => false, "message" => "Password salah"));
            }
        } else {
            http_response_code(401);
            echo json_encode(array("success" => false, "message" => "Email tidak ditemukan"));
        }
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Error: " . $e->getMessage()));
    }
}

function logout() {
    // Hancurkan semua data sesi
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    echo json_encode(array("success" => true, "message" => "Logout berhasil"));
}


// Fungsi baru untuk soft delete akun
function delete_account($db, $data) {
    try {
        if (empty($data['user_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required.']);
            return;
        }

        $user_id = $data['user_id'];

        // Gunakan NOW() atau CURRENT_TIMESTAMP untuk mengisi kolom delete_at
        $query = "UPDATE users SET delete_at = NOW() WHERE user_id = :user_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Account successfully marked for deletion.']);
            } else {
                // Kemungkinan user_id tidak ditemukan
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found.']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete account.']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Memproses permintaan reset kata sandi, membuat token, dan mengirim email.
 */
function request_password_reset($db, $data) {
    if (empty($data['email']) || empty($data['nama'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nama dan Email harus diisi.']);
        return;
    }

    try {
        $email = $data['email'];
        $nama = $data['nama'];

        //Cek apakah email dan nama ada di database
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = :email AND nama = :nama AND delete_at IS NULL");
        $stmt->execute(['email' => $email, 'nama' => $nama]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Kombinasi nama dan email tidak ditemukan.']);
            return;
        }

        $user_id = $user['user_id'];
        
        $user_id = $user['user_id'];
        $token = bin2hex(random_bytes(32)); 
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', time() + 1800);

        //Simpan hash token ke database
        $stmt = $db->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)"
        );
        $stmt->execute([
            'user_id' => $user_id,
            'token_hash' => $token_hash,
            'expires_at' => $expires_at
        ]);

        $reset_link = "http://localhost/frontend/pages/reset-password.html?token=" . $token;
        
        echo json_encode([
            'success' => true, 
            'message' => 'Mode Pengembangan: Email tidak dikirim. Gunakan tautan di bawah ini untuk mereset kata sandi.',
            'reset_link' => $reset_link // Kirim link ke frontend
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        // Log error ini di server Anda
        error_log("Password reset request error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan pada server.']);
    }
}


/**
 * Memvalidasi token dan memperbarui kata sandi pengguna.
 */
function reset_password($db, $data) {
    if (empty($data['token']) || empty($data['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token dan kata sandi baru harus diisi.']);
        return;
    }

    if (strlen($data['password']) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Kata sandi minimal harus 8 karakter.']);
        return;
    }

    try {
        $token = $data['token'];
        $token_hash = hash('sha256', $token);
        $new_password = password_hash($data['password'], PASSWORD_DEFAULT);

        // 1. Cari token di database dan pastikan belum kedaluwarsa
        $stmt = $db->prepare("SELECT user_id, expires_at FROM password_resets WHERE token_hash = :token_hash");
        $stmt->execute(['token_hash' => $token_hash]);
        $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset_request) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token tidak valid.']);
            return;
        }

        if (strtotime($reset_request['expires_at']) < time()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token telah kedaluwarsa. Silakan minta tautan baru.']);
            // Hapus token yang kedaluwarsa
            $db->prepare("DELETE FROM password_resets WHERE token_hash = :token_hash")->execute(['token_hash' => $token_hash]);
            return;
        }

        $user_id = $reset_request['user_id'];

        // 2. Update kata sandi pengguna di tabel 'users'
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE user_id = :user_id");
        $stmt->execute(['password' => $new_password, 'user_id' => $user_id]);

        // 3. Hapus token yang sudah digunakan dari 'password_resets'
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);

        echo json_encode(['success' => true, 'message' => 'Kata sandi Anda telah berhasil diperbarui!']);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Password reset error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan pada server.']);
    }
}

function update_theme($db, $data) {
    if (empty($data['user_id']) || empty($data['theme'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID dan tema diperlukan.']);
        return;
    }
    
    $user_id = $data['user_id'];
    $theme = $data['theme'];
    
    try {
        $stmt = $db->prepare("UPDATE users SET theme = :theme WHERE user_id = :user_id");
        $stmt->bindParam(':theme', $theme);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tema berhasil diperbarui.']);
        } else {
            throw new Exception("Gagal memperbarui tema di database.");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function change_password($db, $data, $user_id) {
    // Validasi input
    if (empty($data['current_password']) || empty($data['new_password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Kata sandi saat ini dan kata sandi baru harus diisi.']);
        return;
    }

    // Validasi panjang password baru
    if (strlen($data['new_password']) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Kata sandi baru minimal harus 8 karakter.']);
        return;
    }

    try {
        // 1. Ambil hash password saat ini dari database
        $stmt = $db->prepare("SELECT password FROM users WHERE user_id = :user_id AND delete_at IS NULL");
        $stmt->execute(['user_id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pengguna tidak ditemukan.']);
            return;
        }

        // 2. Verifikasi kata sandi saat ini
        if (!password_verify($data['current_password'], $user['password'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Kata sandi saat ini salah.']);
            return;
        }

        // 3. Hash kata sandi baru
        $new_password_hashed = password_hash($data['new_password'], PASSWORD_DEFAULT);

        // 4. Update kata sandi di database
        $update_stmt = $db->prepare("UPDATE users SET password = :new_password WHERE user_id = :user_id");
        $update_stmt->execute([
            'new_password' => $new_password_hashed,
            'user_id' => $user_id
        ]);

        echo json_encode(['success' => true, 'message' => 'Kata sandi berhasil diperbarui.']);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Change password error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan pada server.']);
    }
}
?>