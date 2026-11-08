# Acadance — Assignment Dependency & Deadline Planner

A student planner with role-based access control (RBAC): **Student** and **Admin**.

## Included modules
- Secure registration/login/logout, password hashing, session auth and CSRF protection
- **Role-based access control (Student / Admin)**
- Per-user course and assignment management
- Tasks, subtasks, due dates, effort estimates, status and priority
- Dashboard counters, upcoming planner and calendar/deadline list
- Task activity logs and rule-based overdue / due-soon risk indicators

Note: the prerequisite-dependency and notification features were removed from the UI and API. Their tables (`task_dependencies`, `notifications`) still exist in `schema.sql` but are unused.

## Roles (Student / Admin)

| | Student | Admin |
|---|---|---|
| Register from the website | Yes (always becomes `student`) | No |
| Use own planner (courses, assignments, tasks) | Yes | No (403) |
| See list of all students (name, email, joined date) | No (403) | Yes |
| Delete a student | No (403) | Yes |
| Add a student manually | — | **Not possible** (no button, no endpoint) |
| Delete self / another admin | — | Not possible |

Notes:
- Passwords and hashes are never returned by the API or shown in the admin panel.
- Deleting a student also deletes their courses, assignments, tasks and logs (`ON DELETE CASCADE`).
- The role is never read from user input. Registration always creates a `student`.
- Admin accounts can only be created from the command line (see below).

### Admin API (admin session required, writes need the CSRF token)
- `GET    /api/admin/students` — list students (`user_id`, `name`, `email`, `created_at`)
- `DELETE /api/admin/students/{id}` — remove a student (only accounts with role `student`)

### Create an admin
```bash
php database/create_admin.php "Admin Name" admin@example.com "StrongPassword123"
```
If the email already exists, that account is promoted to admin.

### Upgrade an existing database (adds the `role` column)
```bash
sudo mysql acadance_db < database/add_roles.sql
```
Fresh installs do not need this, because `schema.sql` already includes the `role` column. All existing users stay students.

## Project structure
```
acadance/
├── apache/acadance.conf          Apache virtual host
├── config/                       Not web-accessible (Require all denied)
│   ├── app.php, bootstrap.php, database.php
│   └── core/                     Auth.php (incl. requireAdmin), Database.php, Response.php
├── database/
│   ├── schema.sql                Full schema (fresh install)
│   ├── add_roles.sql             Migration for existing databases
│   └── create_admin.php          CLI-only admin creation
└── public/                       Apache document root
    ├── index.html                Student dashboard + Admin panel
    ├── api/index.php             API router
    └── assets/                   css, js
```

## Ubuntu LAMP setup
1. Copy this project so `/var/www/acadance` contains `config/`, `database/`, `public/` and `README.md`.
2. Install requirements: `sudo apt update && sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-cli`.
3. Import schema: `sudo mysql < /var/www/acadance/database/schema.sql`.
4. Create a DB user and grant permissions (replace the password):
   `sudo mysql -e "CREATE USER IF NOT EXISTS 'acadance_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD'; GRANT ALL PRIVILEGES ON acadance_db.* TO 'acadance_user'@'localhost'; FLUSH PRIVILEGES;"`
5. Edit `config/database.php` and set the same password.
6. Set permissions: `sudo chown -R www-data:www-data /var/www/acadance && sudo chmod 640 /var/www/acadance/config/database.php`.
7. Create the first admin: `php /var/www/acadance/database/create_admin.php "Admin" admin@example.com "StrongPassword123"`.
8. Copy `apache/acadance.conf` to `/etc/apache2/sites-available/acadance.conf`.
9. Enable: `sudo a2enmod rewrite && sudo a2ensite acadance.conf && sudo apache2ctl configtest && sudo systemctl reload apache2`.
10. Add `127.0.0.1 acadance.local` to `/etc/hosts` and open `http://acadance.local`.

If Apache reports another default site, disable it with `sudo a2dissite 000-default.conf` only if you do not need it. `file:///var/www/...` bypasses PHP, Apache and the database; always use the HTTP URL for the application.

## Testing the roles
1. Register two students (`s1@test.com`, `s2@test.com`). Each sees the normal planner.
2. Log in as the admin. Only the Admin Panel is shown, listing the students with Name, Email and Joined date, and a Delete button. There is no Add button.
3. Delete `s2`. They disappear from the list and can no longer log in.
4. Browser console checks (as a student): `fetch('api/admin/students',{credentials:'same-origin'}).then(r=>r.status)` returns `403`.
5. As admin: `fetch('api/courses',{credentials:'same-origin'}).then(r=>r.status)` returns `403`.
6. Registering with `role: "admin"` in the request body still creates a `student`.

## Security notes
- Never upload or share `config/database.php` or your DB password. Change the password if it was shared.
- Do not leave backup files (for example `app.js.bak`) inside `public/`; they are downloadable.
- The SQL schema is intended for a fresh Acadance database. Back up an existing database before importing it.
- Set `session_secure` to `true` in `config/app.php` when you serve the site over HTTPS.

## Apache troubleshooting
- `sudo systemctl status apache2`
- `sudo apache2ctl -S`
- `sudo tail -n 60 /var/log/apache2/error.log`
- `curl -I http://acadance.local`
