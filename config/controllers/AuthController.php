<?php
declare(strict_types=1);

final class AuthController
{
    public static function register(PDO $pdo): never
    {
        $data = Response::input();
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            Response::json(['error' => 'Enter a name, valid email and password of at least 8 characters.'], 422);
        }

        $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$email]);

        if ($check->fetch()) {
            Response::json(['error' => 'This email is already registered.'], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'email' => $email,
        ];

        Response::json(['user' => $_SESSION['user'], 'csrfToken' => Auth::csrfToken()], 201);
    }

    public static function login(PDO $pdo): never
    {
        $data = Response::input();
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        $stmt = $pdo->prepare('SELECT user_id, name, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::json(['error' => 'Email or password is incorrect.'], 401);
        }

        session_regenerate_id(true);
        unset($user['password_hash']);
        $user['user_id'] = (int) $user['user_id'];
        $_SESSION['user'] = $user;

        Response::json(['user' => $user, 'csrfToken' => Auth::csrfToken()]);
    }

    public static function logout(): never
    {
        Auth::verifyCsrf();
        $_SESSION = [];
        session_destroy();
        Response::json(['message' => 'Logged out.']);
    }

    public static function me(): never
    {
        Response::json([
            'user' => Auth::user(),
            'csrfToken' => Auth::csrfToken(),
        ]);
    }
}
