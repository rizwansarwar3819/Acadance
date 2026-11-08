<?php
declare(strict_types=1);

// Run from the command line ONLY:
//   php database/create_admin.php "Admin Name" admin@example.com "StrongPassword123"
// There is deliberately no web page or API endpoint for creating admins.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php database/create_admin.php \"Name\" email password\n");
    exit(1);
}

[, $name, $email, $password] = $argv;
$email = strtolower(trim($email));

if (trim($name) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "Need a name, a valid email and a password of 8+ characters.\n");
    exit(1);
}

require_once __DIR__ . '/../config/core/Database.php';
$db = Database::connection();

$stmt = $db->prepare('SELECT user_id, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    // Promote an existing account to admin
    $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?");
    $stmt->execute([$existing['user_id']]);
    echo "Existing account {$email} is now an admin.\n";
    exit(0);
}

$stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute([trim($name), $email, password_hash($password, PASSWORD_DEFAULT)]);
echo "Admin {$email} created.\n";
