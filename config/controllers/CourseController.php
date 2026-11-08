<?php
declare(strict_types=1);

final class CourseController
{
    public static function index(PDO $pdo, array $user): never
    {
        $stmt = $pdo->prepare(
            'SELECT course_id, course_name, course_code, description, created_at
             FROM courses WHERE user_id = ? ORDER BY course_name'
        );
        $stmt->execute([$user['user_id']]);
        Response::json(['courses' => $stmt->fetchAll()]);
    }

    public static function store(PDO $pdo, array $user): never
    {
        $data = Response::input();
        $name = trim($data['course_name'] ?? '');

        if ($name === '') {
            Response::json(['error' => 'Course name is required.'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO courses (user_id, course_name, course_code, description)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['user_id'],
            $name,
            trim($data['course_code'] ?? '') ?: null,
            trim($data['description'] ?? '') ?: null,
        ]);

        Response::json(['message' => 'Course created.', 'course_id' => (int) $pdo->lastInsertId()], 201);
    }
}
