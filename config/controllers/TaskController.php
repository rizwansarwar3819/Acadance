<?php
declare(strict_types=1);

final class TaskController
{
    public static function index(PDO $pdo, array $user): never
    {
        $stmt = $pdo->prepare(
            'SELECT t.task_id, t.assignment_id, t.parent_task_id, t.title, t.description,
                    t.due_at, t.estimated_hours, t.priority, t.status, a.title AS assignment_title
             FROM tasks t
             INNER JOIN assignments a ON a.assignment_id = t.assignment_id
             INNER JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY t.due_at'
        );
        $stmt->execute([$user['user_id']]);
        Response::json(['tasks' => $stmt->fetchAll()]);
    }

    public static function store(PDO $pdo, array $user): never
    {
        $data = Response::input();
        $assignmentId = filter_var($data['assignment_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim($data['title'] ?? '');
        $priority = $data['priority'] ?? 'medium';

        if (!$assignmentId || $title === '' || !in_array($priority, ['low', 'medium', 'high'], true)) {
            Response::json(['error' => 'Valid assignment, title and priority are required.'], 422);
        }

        $check = $pdo->prepare(
            'SELECT a.assignment_id FROM assignments a
             INNER JOIN courses c ON c.course_id = a.course_id
             WHERE a.assignment_id = ? AND c.user_id = ?'
        );
        $check->execute([$assignmentId, $user['user_id']]);

        if (!$check->fetch()) {
            Response::json(['error' => 'Assignment not found.'], 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tasks (assignment_id, title, description, due_at, estimated_hours, priority)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assignmentId,
            $title,
            trim($data['description'] ?? '') ?: null,
            (!empty($data['due_at']) ? str_replace('T', ' ', $data['due_at']) : null),
            max(0, (float) ($data['estimated_hours'] ?? 0)),
            $priority,
        ]);

        Response::json(['message' => 'Task created.', 'task_id' => (int) $pdo->lastInsertId()], 201);
    }

    public static function updateStatus(PDO $pdo, array $user, int $taskId): never
    {
        $data = Response::input();
        $status = $data['status'] ?? '';

        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
            Response::json(['error' => 'Invalid task status.'], 422);
        }

        $check = $pdo->prepare(
            'SELECT t.task_id FROM tasks t
             INNER JOIN assignments a ON a.assignment_id = t.assignment_id
             INNER JOIN courses c ON c.course_id = a.course_id
             WHERE t.task_id = ? AND c.user_id = ?'
        );
        $check->execute([$taskId, $user['user_id']]);

        if (!$check->fetch()) {
            Response::json(['error' => 'Task not found.'], 404);
        }

        $stmt = $pdo->prepare(
            "UPDATE tasks SET status = ?, completed_at = CASE WHEN ? = 'completed'
             THEN CURRENT_TIMESTAMP ELSE NULL END WHERE task_id = ?"
        );
        $stmt->execute([$status, $status, $taskId]);

        $log = $pdo->prepare('INSERT INTO task_logs (task_id, action, details) VALUES (?, ?, ?)');
        $log->execute([$taskId, 'status_updated', 'Status changed to ' . $status]);

        Response::json(['message' => 'Task status updated.']);
    }
}
