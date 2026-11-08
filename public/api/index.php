<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

/**
 * Check that a task belongs to the logged-in user, then return it.
 */
function ownedTask(PDO $db, int $id, int $uid): array
{
    $stmt = $db->prepare(
        'SELECT t.*
         FROM tasks t
         JOIN assignments a ON a.assignment_id = t.assignment_id
         JOIN courses c ON c.course_id = a.course_id
         WHERE t.task_id = ? AND c.user_id = ?'
    );
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();

    if (!$row) {
        Response::json(['error' => 'Task not found.'], 404);
    }

    return $row;
}

/**
 * Write an entry into the task log.
 */
function logTask(PDO $db, int $id, string $action, string $details = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO task_logs (task_id, action, details) VALUES (?, ?, ?)'
    );
    $stmt->execute([$id, $action, $details]);
}

try {
    $db = Database::connection();
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

    // Works both at domain root and if deployed in a subdirectory.
    $path = trim(preg_replace('#^.*/api/?#', '', rtrim($uri, '/')), '/');

    $input = Response::input();

    // ---------------------------------------------------------------
    // AUTH
    // ---------------------------------------------------------------

    // Current logged-in user
    if ($method === 'GET' && $path === 'auth/me') {
        Response::json(['user' => Auth::user(), 'csrfToken' => Auth::csrfToken()]);
    }

    // Register a new user
    if ($method === 'POST' && $path === 'auth/register') {
        $name  = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $pw    = $input['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) {
            Response::json(['error' => 'Enter a name, valid email and password (8+ characters).'], 422);
        }

        $stmt = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');

        try {
            $stmt->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::json(['error' => 'Email already registered.'], 409);
            }
            throw $e;
        }

        session_regenerate_id(true);
        // Role is NEVER taken from user input: new accounts are always students
        // (the DB column also defaults to 'student').
        $_SESSION['user'] = [
            'user_id' => (int) $db->lastInsertId(),
            'name'    => $name,
            'email'   => $email,
            'role'    => 'student',
        ];

        Response::json(['user' => $_SESSION['user'], 'csrfToken' => Auth::csrfToken()], 201);
    }

    // Login
    if ($method === 'POST' && $path === 'auth/login') {
        $stmt = $db->prepare('SELECT user_id, name, email, role, password_hash FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($input['email'] ?? ''))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($input['password'] ?? '', $user['password_hash'])) {
            Response::json(['error' => 'Email or password is incorrect.'], 401);
        }

        unset($user['password_hash']);
        $user['user_id'] = (int) $user['user_id'];

        session_regenerate_id(true);
        $_SESSION['user'] = $user;

        Response::json(['user' => $user, 'csrfToken' => Auth::csrfToken()]);
    }

    // From here on a logged-in user is required; writes also need CSRF.
    $user = Auth::requireUser();
    $uid  = (int) $user['user_id'];

    if ($method !== 'GET') {
        Auth::verifyCsrf();
    }

    // Logout
    if ($method === 'POST' && $path === 'auth/logout') {
        $_SESSION = [];
        session_destroy();
        Response::json(['message' => 'Logged out']);
    }

    // ---------------------------------------------------------------
    // ADMIN (role = admin only). View + Delete students. No "add" endpoint.
    // ---------------------------------------------------------------

    // List all students (no password / hash is ever selected)
    if ($method === 'GET' && $path === 'admin/students') {
        Auth::requireAdmin();

        $stmt = $db->prepare(
            "SELECT user_id, name, email, created_at
             FROM users
             WHERE role = 'student'
             ORDER BY created_at DESC, user_id DESC"
        );
        $stmt->execute();

        Response::json(['students' => $stmt->fetchAll()]);
    }

    // Remove a student (their courses/assignments/tasks are deleted via ON DELETE CASCADE)
    if ($method === 'DELETE' && preg_match('#^admin/students/(\d+)$#', $path, $m)) {
        Auth::requireAdmin();
        $id = (int) $m[1];

        // Only student accounts can be removed: an admin can't delete themselves or another admin.
        $stmt = $db->prepare("DELETE FROM users WHERE user_id = ? AND role = 'student'");
        $stmt->execute([$id]);

        if ($stmt->rowCount() !== 1) {
            Response::json(['error' => 'Student not found.'], 404);
        }

        Response::json(['message' => 'Student removed.']);
    }

    // Admins only manage students; the personal planner endpoints below are for students.
    if (Auth::isAdmin()) {
        Response::json(['error' => 'Not available for admin accounts.'], 403);
    }

    // ---------------------------------------------------------------
    // COURSES
    // ---------------------------------------------------------------

    // List my courses
    if ($method === 'GET' && $path === 'courses') {
        $stmt = $db->prepare('SELECT * FROM courses WHERE user_id = ? ORDER BY course_name');
        $stmt->execute([$uid]);
        Response::json(['courses' => $stmt->fetchAll()]);
    }

    // Create a course
    if ($method === 'POST' && $path === 'courses') {
        $name = trim($input['course_name'] ?? '');

        if (!$name) {
            Response::json(['error' => 'Course name is required.'], 422);
        }

        $stmt = $db->prepare(
            'INSERT INTO courses (user_id, course_name, course_code, description)
             VALUES (?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $uid,
                $name,
                trim($input['course_code'] ?? '') ?: null,
                trim($input['description'] ?? '') ?: null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                Response::json(['error' => 'A course with this name already exists.'], 409);
            }
            throw $e;
        }

        Response::json(['message' => 'Course created.', 'course_id' => (int) $db->lastInsertId()], 201);
    }

    // Update an existing course owned by the logged-in user
    if ($method === 'PATCH' && preg_match('#^courses/(\d+)$#', $path, $m)) {
        $id          = (int) $m[1];
        $name        = $input['course_name'] ?? null;
        $courseCode  = $input['course_code'] ?? '';
        $description = $input['description'] ?? '';

        if (!is_string($name) || !is_string($courseCode) || !is_string($description)) {
            Response::json(['error' => 'Invalid course information.'], 422);
        }

        $name        = trim($name);
        $courseCode  = trim($courseCode);
        $description = trim($description);

        if ($name === '') {
            Response::json(['error' => 'Course name is required.'], 422);
        }

        $stmt = $db->prepare('SELECT course_id FROM courses WHERE course_id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);

        if (!$stmt->fetch()) {
            Response::json(['error' => 'Course not found.'], 404);
        }

        $stmt = $db->prepare(
            'UPDATE courses SET course_name = ?, course_code = ?, description = ?
             WHERE course_id = ? AND user_id = ?'
        );

        try {
            $stmt->execute([$name, $courseCode ?: null, $description ?: null, $id, $uid]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                Response::json(['error' => 'A course with this name already exists.'], 409);
            }
            throw $e;
        }

        Response::json(['message' => 'Course updated.']);
    }

    // Delete a single course owned by the logged-in user
    if ($method === 'DELETE' && preg_match('#^courses/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];

        $stmt = $db->prepare('DELETE FROM courses WHERE course_id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);

        if ($stmt->rowCount() !== 1) {
            Response::json(['error' => 'Course not found.'], 404);
        }

        Response::json(['message' => 'Course and its related data deleted.']);
    }

    // Delete ALL courses belonging to the logged-in user
    if ($method === 'DELETE' && $path === 'courses') {
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM courses WHERE user_id = ?');
            $stmt->execute([$uid]);
            $total = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('DELETE FROM courses WHERE user_id = ?');
            $stmt->execute([$uid]);
            $deleted = $stmt->rowCount();

            $db->commit();

            Response::json([
                'message'       => 'All your courses and related data deleted.',
                'deleted_count' => $deleted,
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // ASSIGNMENTS
    // ---------------------------------------------------------------

    // List my assignments (with course name)
    if ($method === 'GET' && $path === 'assignments') {
        $stmt = $db->prepare(
            'SELECT a.*, c.course_name
             FROM assignments a
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY a.deadline'
        );
        $stmt->execute([$uid]);
        Response::json(['assignments' => $stmt->fetchAll()]);
    }

    // Create an assignment
    if ($method === 'POST' && $path === 'assignments') {
        $cid      = (int) ($input['course_id'] ?? 0);
        $title    = trim($input['title'] ?? '');
        $deadline = str_replace('T', ' ', $input['deadline'] ?? '');

        $stmt = $db->prepare('SELECT 1 FROM courses WHERE course_id = ? AND user_id = ?');
        $stmt->execute([$cid, $uid]);

        if (!$stmt->fetch() || !$title || !$deadline) {
            Response::json(['error' => 'Select your course and provide title and deadline.'], 422);
        }

        $stmt = $db->prepare(
            'INSERT INTO assignments (course_id, title, description, deadline)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $cid,
            $title,
            trim($input['description'] ?? '') ?: null,
            $deadline,
        ]);

        Response::json(['message' => 'Assignment created.'], 201);
    }

    // Update an assignment owned by the logged-in user
    if ($method === 'PATCH' && preg_match('#^assignments/(\d+)$#', $path, $m)) {
        $id          = (int) $m[1];
        $cid         = (int) ($input['course_id'] ?? 0);
        $title       = $input['title'] ?? null;
        $deadline    = $input['deadline'] ?? null;
        $description = $input['description'] ?? '';

        if (!is_string($title) || !is_string($deadline) || !is_string($description)) {
            Response::json(['error' => 'Invalid assignment information.'], 422);
        }

        $title       = trim($title);
        $deadline    = str_replace('T', ' ', $deadline);
        $description = trim($description);

        if ($cid < 1 || $title === '' || strlen($title) > 200 || $deadline === '') {
            Response::json(['error' => 'Provide a valid course, title and deadline.'], 422);
        }

        $stmt = $db->prepare('SELECT 1 FROM courses WHERE course_id = ? AND user_id = ?');
        $stmt->execute([$cid, $uid]);

        if (!$stmt->fetch()) {
            Response::json(['error' => 'Course not found.'], 404);
        }

        $stmt = $db->prepare(
            'UPDATE assignments a
             JOIN courses c ON c.course_id = a.course_id
             SET a.course_id = ?, a.title = ?, a.description = ?, a.deadline = ?
             WHERE a.assignment_id = ? AND c.user_id = ?'
        );
        $stmt->execute([$cid, $title, $description ?: null, $deadline, $id, $uid]);

        // rowCount() is 0 when nothing actually changed, so verify ownership separately.
        if ($stmt->rowCount() === 0) {
            $check = $db->prepare(
                'SELECT 1
                 FROM assignments a
                 JOIN courses c ON c.course_id = a.course_id
                 WHERE a.assignment_id = ? AND c.user_id = ?'
            );
            $check->execute([$id, $uid]);

            if (!$check->fetch()) {
                Response::json(['error' => 'Assignment not found.'], 404);
            }
        }

        Response::json(['message' => 'Assignment updated.']);
    }

    // Delete an assignment owned by the logged-in user
    if ($method === 'DELETE' && preg_match('#^assignments/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];

        $stmt = $db->prepare(
            'DELETE a
             FROM assignments a
             JOIN courses c ON c.course_id = a.course_id
             WHERE a.assignment_id = ? AND c.user_id = ?'
        );
        $stmt->execute([$id, $uid]);

        if ($stmt->rowCount() !== 1) {
            Response::json(['error' => 'Assignment not found.'], 404);
        }

        Response::json(['message' => 'Assignment and related data deleted.']);
    }

    // ---------------------------------------------------------------
    // TASKS
    // ---------------------------------------------------------------

    // List my tasks, sorted by effective due date
    if ($method === 'GET' && $path === 'tasks') {
        $stmt = $db->prepare(
            "SELECT t.*, a.title AS assignment_title, c.course_name
             FROM tasks t
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY COALESCE(t.due_at, a.deadline), t.task_id"
        );
        $stmt->execute([$uid]);
        Response::json(['tasks' => $stmt->fetchAll()]);
    }

    // Create a task (optionally as a subtask of another task)
    if ($method === 'POST' && $path === 'tasks') {
        $aid      = (int) ($input['assignment_id'] ?? 0);
        $title    = trim($input['title'] ?? '');
        $priority = $input['priority'] ?? 'medium';

        $stmt = $db->prepare(
            'SELECT 1
             FROM assignments a
             JOIN courses c ON c.course_id = a.course_id
             WHERE a.assignment_id = ? AND c.user_id = ?'
        );
        $stmt->execute([$aid, $uid]);

        if (!$stmt->fetch() || !$title || !in_array($priority, ['low', 'medium', 'high'], true)) {
            Response::json(['error' => 'Valid assignment, title and priority are required.'], 422);
        }

        // Optional parent task: it must belong to the same assignment.
        $parent = isset($input['parent_task_id']) && $input['parent_task_id'] !== ''
            ? (int) $input['parent_task_id']
            : null;

        if ($parent) {
            $parentRow = ownedTask($db, $parent, $uid);
            if ((int) $parentRow['assignment_id'] !== $aid) {
                Response::json(['error' => 'Subtask must belong to the same assignment.'], 422);
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO tasks (assignment_id, parent_task_id, title, description, due_at, estimated_hours, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $aid,
            $parent,
            $title,
            trim($input['description'] ?? '') ?: null,
            ($input['due_at'] ?? '') ? str_replace('T', ' ', $input['due_at']) : null,
            max(0, (float) ($input['estimated_hours'] ?? 0)),
            $priority,
        ]);

        $id = (int) $db->lastInsertId();
        logTask($db, $id, 'created', 'Task created');

        Response::json(['message' => 'Task created.', 'task_id' => $id], 201);
    }

    // Change a task's status (pending / in_progress / completed)
    if ($method === 'PATCH' && preg_match('#^tasks/(\d+)/status$#', $path, $m)) {
        $id     = (int) $m[1];
        ownedTask($db, $id, $uid); // ownership check

        $status = $input['status'] ?? '';

        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
            Response::json(['error' => 'Invalid status.'], 422);
        }

        $stmt = $db->prepare(
            "UPDATE tasks
             SET status = ?, completed_at = IF(? = 'completed', CURRENT_TIMESTAMP, NULL)
             WHERE task_id = ?"
        );
        $stmt->execute([$status, $status, $id]);

        logTask($db, $id, 'status_updated', 'Status changed to ' . $status);

        Response::json(['message' => 'Task status updated.']);
    }

    // ---------------------------------------------------------------
    // DASHBOARD / PLANNER / CALENDAR
    // ---------------------------------------------------------------

    // Dashboard statistics
    if ($method === 'GET' && $path === 'dashboard') {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT c.course_id) AS courses,
                    (SELECT COUNT(*)
                       FROM assignments a
                       JOIN courses c2 ON c2.course_id = a.course_id
                      WHERE c2.user_id = ?) AS assignments,
                    (SELECT COUNT(*)
                       FROM tasks t
                       JOIN assignments a2 ON a2.assignment_id = t.assignment_id
                       JOIN courses c3 ON c3.course_id = a2.course_id
                      WHERE c3.user_id = ?) AS tasks,
                    (SELECT COUNT(*)
                       FROM tasks t
                       JOIN assignments a3 ON a3.assignment_id = t.assignment_id
                       JOIN courses c4 ON c4.course_id = a3.course_id
                      WHERE c4.user_id = ? AND t.status = 'completed') AS completed
             FROM courses c
             WHERE c.user_id = ?"
        );
        $stmt->execute([$uid, $uid, $uid, $uid]);
        $stats = $stmt->fetch();

        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM tasks t
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ? AND t.status <> 'completed'
               AND COALESCE(t.due_at, a.deadline) < NOW()"
        );
        $stmt->execute([$uid]);
        $stats['overdue'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM tasks t
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ? AND t.status <> 'completed'
               AND COALESCE(t.due_at, a.deadline) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)"
        );
        $stmt->execute([$uid]);
        $stats['at_risk'] = (int) $stmt->fetchColumn();

        Response::json(['stats' => $stats]);
    }

    // Planner: all tasks ordered by effective due date
    if ($method === 'GET' && $path === 'planner') {
        $stmt = $db->prepare(
            'SELECT t.task_id, t.title, t.status, t.priority, t.due_at, t.estimated_hours,
                    a.title AS assignment_title, c.course_name
             FROM tasks t
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY COALESCE(t.due_at, a.deadline)'
        );
        $stmt->execute([$uid]);
        Response::json(['items' => $stmt->fetchAll()]);
    }

    // Calendar: tasks with a due date + assignment deadlines
    if ($method === 'GET' && $path === 'calendar') {
        $stmt = $db->prepare(
            "SELECT t.task_id, t.title, t.due_at, t.status, 'task' AS item_type
             FROM tasks t
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ? AND t.due_at IS NOT NULL

             UNION ALL

             SELECT a.assignment_id AS task_id, a.title, a.deadline AS due_at, a.status,
                    'assignment' AS item_type
             FROM assignments a
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?

             ORDER BY due_at"
        );
        $stmt->execute([$uid, $uid]);
        Response::json(['events' => $stmt->fetchAll()]);
    }

    // ---------------------------------------------------------------
    // LOGS
    // ---------------------------------------------------------------

    // Recent activity log
    if ($method === 'GET' && $path === 'logs') {
        $stmt = $db->prepare(
            'SELECT l.*, t.title AS task_title
             FROM task_logs l
             JOIN tasks t ON t.task_id = l.task_id
             JOIN assignments a ON a.assignment_id = t.assignment_id
             JOIN courses c ON c.course_id = a.course_id
             WHERE c.user_id = ?
             ORDER BY l.logged_at DESC
             LIMIT 150'
        );
        $stmt->execute([$uid]);
        Response::json(['logs' => $stmt->fetchAll()]);
    }

    // Nothing matched
    Response::json(['error' => 'Endpoint not found.', 'path' => $path], 404);

} catch (Throwable $e) {
    error_log('[Acadance API] ' . $e->getMessage());
    Response::json(['error' => 'Server error. Check Apache error log and database setup.'], 500);
}