<?php
// Login endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

// Validate input
if (empty($email) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'E-Mail und Passwort sind erforderlich'
    ]);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Get user by email
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige E-Mail oder Passwort'
        ]);
        exit;
    }
    
    // Check if user logged in with Google (no password)
    if (empty($user['password_hash']) && !empty($user['google_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Dieses Konto wurde mit Google erstellt. Bitte melden Sie sich mit Google an.'
        ]);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige E-Mail oder Passwort'
        ]);
        exit;
    }
    
    // Update last login
    $updateStmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $updateStmt->execute([$user['id']]);
    
    // Generate JWT token
    $token = generateJWT($user['id'], $user['email']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Anmeldung erfolgreich',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'avatar' => $user['avatar_url']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Anmeldung fehlgeschlagen: ' . $e->getMessage()
    ]);
}

// Helper function to generate JWT token
function generateJWT($userId, $email) {
    $secret_key = 'your_jwt_secret_key_here_change_this_in_production';
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userId,
        'email' => $email,
        'exp' => time() + 3600 // 1 hour expiration
    ]);
    
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret_key, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

// Helper function to get database connection
function getDBConnection() {
    $host = 'localhost';
    $dbname = 'bazar_marketplace';
    $username = 'root';
    $password = '';
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(PDOException $e) {
        throw new Exception("Connection failed: " . $e->getMessage());
    }
}
?>