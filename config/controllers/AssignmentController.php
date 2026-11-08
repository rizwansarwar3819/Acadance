<?php
declare(strict_types=1);

final class AssignmentController
{
    public static function index(PDO $pdo, array $user): never
    {
        $stmt = $pdo->prepare(
            'SELECT a.assignment_id, a.course_id, a.title, a.description, a.deadline, a.status,
                    c.course_name
             FROM assignments a
             INNER JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY a.deadline'
        );
        $stmt->execute([$user['user_id']]);
        Response::json(['assignments' => $stmt->fetchAll()]);
    }

    public static function store(PDO $pdo, array $user): never
    {
        $data = Response::input();
        $courseId = filter_var($data['course_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim($data['title'] ?? '');
        $deadline = str_replace('T', ' ', trim($data['deadline'] ?? ''));

        if (!$courseId || $title === '' || $deadline === '') {
            Response::json(['error' => 'Course, title and deadline are required.'], 422);
        }

        $check = $pdo->prepare('SELECT course_id FROM courses WHERE course_id = ? AND user_id = ?');
        $check->execute([$courseId, $user['user_id']]);

        if (!$check->fetch()) {
            Response::json(['error' => 'Course not found.'], 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO assignments (course_id, title, description, deadline)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$courseId, $title, trim($data['description'] ?? '') ?: null, $deadline]);

        Response::json(['message' => 'Assignment created.', 'assignment_id' => (int) $pdo->lastInsertId()], 201);
    }
}
