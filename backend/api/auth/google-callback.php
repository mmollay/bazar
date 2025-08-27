<?php
// Google OAuth Callback Handler
session_start();

// Load configuration
require_once '../../config/config.php';

// Google OAuth configuration
$client_id = '794380625119-03ocmimq1o84edhuvvltpd6p97earr9a.apps.googleusercontent.com';
$client_secret = 'GOCSPX-YOUR_SECRET_HERE'; // You need to get this from Google Console
$redirect_uri = 'http://localhost/bazar/backend/api/auth/google-callback.php';

// Check if we have an authorization code
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange authorization code for access token
    $token_url = 'https://oauth2.googleapis.com/token';
    $token_data = [
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $token_response = curl_exec($ch);
    curl_close($ch);
    
    $token = json_decode($token_response, true);
    
    if (isset($token['access_token'])) {
        // Get user info using the access token
        $user_info_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
        
        $ch = curl_init($user_info_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token['access_token']
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $user_info_response = curl_exec($ch);
        curl_close($ch);
        
        $user_info = json_decode($user_info_response, true);
        
        if ($user_info && isset($user_info['email'])) {
            // Check if user exists in database
            try {
                $conn = getDBConnection();
                
                // Check if user exists
                $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
                $stmt->execute([$user_info['email'], $user_info['id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Update Google ID if not set
                    if (!$user['google_id']) {
                        $updateStmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                        $updateStmt->execute([$user_info['id'], $user['id']]);
                    }
                } else {
                    // Create new user
                    $username = explode('@', $user_info['email'])[0];
                    $stmt = $conn->prepare("
                        INSERT INTO users (email, username, google_id, first_name, last_name, avatar_url, is_verified, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $stmt->execute([
                        $user_info['email'],
                        $username,
                        $user_info['id'],
                        $user_info['given_name'] ?? '',
                        $user_info['family_name'] ?? '',
                        $user_info['picture'] ?? ''
                    ]);
                    
                    $user = [
                        'id' => $conn->lastInsertId(),
                        'email' => $user_info['email'],
                        'username' => $username,
                        'first_name' => $user_info['given_name'] ?? '',
                        'last_name' => $user_info['family_name'] ?? '',
                        'avatar_url' => $user_info['picture'] ?? ''
                    ];
                }
                
                // Generate JWT token
                $jwt_token = generateJWT($user['id'], $user['email']);
                
                // Send success message to parent window and close popup
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Login erfolgreich</title>
                </head>
                <body>
                    <script>
                        // Send message to parent window
                        if (window.opener) {
                            window.opener.postMessage({
                                type: 'google-auth-success',
                                token: '<?php echo $jwt_token; ?>',
                                user: <?php echo json_encode([
                                    'id' => $user['id'],
                                    'email' => $user['email'],
                                    'username' => $user['username'],
                                    'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                                    'avatar' => $user['avatar_url']
                                ]); ?>
                            }, 'http://localhost');
                            
                            // Close popup after a short delay
                            setTimeout(function() {
                                window.close();
                            }, 1000);
                        }
                    </script>
                    <div style="text-align: center; padding: 50px; font-family: Arial, sans-serif;">
                        <h2>✅ Erfolgreich angemeldet!</h2>
                        <p>Dieses Fenster wird automatisch geschlossen...</p>
                    </div>
                </body>
                </html>
                <?php
                exit;
                
            } catch (Exception $e) {
                $error = 'Datenbankfehler: ' . $e->getMessage();
            }
        } else {
            $error = 'Konnte Benutzerinformationen nicht abrufen';
        }
    } else {
        $error = 'Token-Austausch fehlgeschlagen';
    }
} else {
    $error = 'Kein Autorisierungscode erhalten';
}

// If we get here, there was an error
?>
<!DOCTYPE html>
<html>
<head>
    <title>Anmeldung fehlgeschlagen</title>
</head>
<body>
    <div style="text-align: center; padding: 50px; font-family: Arial, sans-serif;">
        <h2>❌ Anmeldung fehlgeschlagen</h2>
        <p><?php echo htmlspecialchars($error ?? 'Unbekannter Fehler'); ?></p>
        <button onclick="window.close()">Fenster schließen</button>
    </div>
</body>
</html>

<?php
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