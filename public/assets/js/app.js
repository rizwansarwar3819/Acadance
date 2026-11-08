
const state = {
    csrfToken: '',
    user: null,
    courses: [],
    assignments: [],
    tasks: [],
    editingCourseId: null,
    editingAssignmentId: null,
    savingCourse: false
};

const $ = s => document.querySelector(s);

async function api(path, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {})
    };

    if (state.csrfToken && options.method && options.method !== 'GET') {
        headers['X-CSRF-Token'] = state.csrfToken;
    }

    const r = await fetch(`api/${path}`, {
        credentials: 'same-origin',
        ...options,
        headers
    });

    let d = {};
    try {
        d = await r.json();
    } catch { }

    if (!r.ok) throw new Error(d.error || `Request failed (${r.status})`);
    if (d.csrfToken) state.csrfToken = d.csrfToken;

    return d;
}

function msg(sel, text, ok = false) {
    const e = $(sel);
    e.textContent = text;
    e.classList.toggle('success', ok);
}

function formData(f) {
    return Object.fromEntries(new FormData(f).entries());
}

function li(el, title, detail = '') {
    const x = document.createElement('li'),
        a = document.createElement('div');
    a.innerHTML = '';

    const b = document.createElement('span');
    b.className = 'item-title';
    b.textContent = title;
    a.append(b);

    if (detail) {
        const m = document.createElement('span');
        m.className = 'item-meta';
        m.textContent = detail;
        a.append(m);
    }

    x.append(a);
    el.append(x);
}

function option(el, value, label) {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = label;
    el.append(o);
}

function cancelCourseEdit() {
    state.editingCourseId = null;
    $('#courseForm').reset();
    $('#courseSubmitButton').textContent = 'Add course';
    $('#cancelCourseEdit').classList.add('hidden');
}

function cancelAssignmentEdit() {
    state.editingAssignmentId = null;
    $('#assignmentForm').reset();
    $('#assignmentSubmitButton').textContent = 'Add assignment';
    $('#cancelAssignmentEdit').classList.add('hidden');
}

function showAuth() {
    state.user = null;

    $('#authView')?.classList.remove('hidden');
    $('#appView')?.classList.add('hidden');
    $('#adminView')?.classList.add('hidden');
    $('#mainNav')?.classList.add('hidden');

    // Hide user profile and logout on login page
    $('#userActions')?.classList.add('hidden');
    $('#logoutButton')?.classList.add('hidden');

    if ($('#userLabel')) {
        $('#userLabel').textContent = '';
    }

    if ($('#userAvatar')) {
        $('#userAvatar').textContent = '';
    }
}


function showApp(u) {
    console.log("User data:", u);

    state.user = u;

    const isAdmin = u.role === 'admin';

    $('#authView')?.classList.add('hidden');
    $('#appView')?.classList.toggle('hidden', isAdmin);
    $('#adminView')?.classList.toggle('hidden', !isAdmin);
    $('#mainNav')?.classList.remove('hidden');
    $('#logoutButton')?.classList.remove('hidden');
    $('#userActions')?.classList.remove('hidden');

    const userName = (u.name || 'User').trim();

    if ($('#userLabel')) {
        $('#userLabel').textContent = userName;
    }

    if ($('#userAvatar')) {
        $('#userAvatar').textContent =
            userName.charAt(0).toUpperCase();
    }

    if ($('#welcomeTitle')) {
        $('#welcomeTitle').textContent = `Welcome, ${userName}`;
    }

    if (isAdmin) {
        $('#adminWelcome').textContent = `Welcome, ${userName} (Admin)`;
        loadStudents();
    } else {
        refresh();
    }
}

async function loadStudents() {
    try {
        const d = await api('admin/students');
        const tb = $('#studentTable');
        tb.replaceChildren();
        $('#studentCount').textContent = d.students.length;
        $('#studentEmpty').classList.toggle('hidden', d.students.length > 0);

        d.students.forEach((s, i) => {
            const tr = document.createElement('tr');

            const num = document.createElement('td');
            num.textContent = i + 1;

            const name = document.createElement('td');
            name.textContent = s.name;

            const email = document.createElement('td');
            email.textContent = s.email;

            const joined = document.createElement('td');
            joined.textContent = s.created_at || '';

            const act = document.createElement('td');
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn light';
            del.textContent = 'Delete';
            del.addEventListener('click', async () => {
                if (!confirm(`Remove ${s.name} (${s.email})? Their courses, assignments and tasks will also be deleted. This cannot be undone.`)) return;
                try {
                    await api('admin/students/' + s.user_id, { method: 'DELETE', body: '{}' });
                    msg('#adminMessage', 'Student removed.', true);
                    await loadStudents();
                } catch (e) {
                    msg('#adminMessage', e.message);
                }
            });
            act.append(del);

            tr.append(num, name, email, joined, act);
            tb.append(tr);
        });
    } catch (e) {
        msg('#adminMessage', e.message);
    }
}


async function refresh() {
    try {
        const [c, a, t, d, p, cal, l] = await Promise.all([
            'courses', 'assignments', 'tasks', 'dashboard', 'planner', 'calendar', 'logs'
        ].map(x => api(x)));

        state.courses = c.courses;
        state.assignments = a.assignments;
        state.tasks = t.tasks;

        $('#statCourses').textContent = d.stats.courses;
        $('#statAssignments').textContent = d.stats.assignments;
        $('#statCompleted').textContent = `${d.stats.completed} / ${d.stats.tasks}`;
        $('#statOverdue').textContent = d.stats.overdue;
        $('#statRisk').textContent = d.stats.at_risk;

        const cl = $('#courseList'), al = $('#assignmentList');
        cl.replaceChildren();
        al.replaceChildren();

        state.courses.forEach(x => {
            const item = document.createElement('li'),
                details = document.createElement('div'),
                title = document.createElement('span'),
                meta = document.createElement('span'),
                actions = document.createElement('div');

            title.className = 'item-title';
            title.textContent = x.course_name;
            meta.className = 'item-meta';
            meta.textContent = x.course_code || x.description || 'Course';
            details.append(title, meta);

            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn light';
            edit.textContent = 'Edit';
            edit.addEventListener('click', () => {
                state.editingCourseId = Number(x.course_id);
                const f = $('#courseForm');
                f.elements.course_name.value = x.course_name;
                f.elements.course_code.value = x.course_code || '';
                f.elements.description.value = x.description || '';
                $('#courseSubmitButton').textContent = 'Save changes';
                $('#cancelCourseEdit').classList.remove('hidden');
                f.scrollIntoView({ behavior: 'smooth', block: 'center' });
                f.elements.course_name.focus();
            });

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn light';
            del.textContent = 'Delete';
            del.addEventListener('click', async () => {
                if (!confirm('Are you sure? Deleting this course will also delete all its assignments, tasks and related activity.')) return;
                try {
                    await api('courses/' + x.course_id, { method: 'DELETE' });
                    if (state.editingCourseId === Number(x.course_id)) cancelCourseEdit();
                    msg('#appMessage', 'Course and related data deleted.', true);
                    await refresh();
                } catch (e) {
                    msg('#appMessage', e.message);
                }
            });

            actions.append(edit, del);
            item.append(details, actions);
            cl.append(item);
        });

        state.assignments.forEach(x => {
            const item = document.createElement('li'),
                details = document.createElement('div'),
                title = document.createElement('span'),
                meta = document.createElement('span'),
                actions = document.createElement('div');

            title.className = 'item-title';
            title.textContent = x.title;
            meta.className = 'item-meta';
            meta.textContent = `${x.course_name} · Due ${x.deadline} · ${x.status}`;
            details.append(title, meta);

            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn light';
            edit.textContent = 'Edit';
            edit.addEventListener('click', () => {
                state.editingAssignmentId = Number(x.assignment_id);
                const f = $('#assignmentForm');
                f.elements.course_id.value = x.course_id;
                f.elements.title.value = x.title;
                f.elements.deadline.value = String(x.deadline).replace(' ', 'T').slice(0, 16);
                f.elements.description.value = x.description || '';
                $('#assignmentSubmitButton').textContent = 'Save changes';
                $('#cancelAssignmentEdit').classList.remove('hidden');
                f.scrollIntoView({ behavior: 'smooth', block: 'center' });
                f.elements.title.focus();
            });

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn light';
            del.textContent = 'Delete';
            del.addEventListener('click', async () => {
                if (!confirm('Are you sure? Deleting this assignment will also delete all its tasks and related activity.')) return;
                try {
                    await api('assignments/' + x.assignment_id, { method: 'DELETE' });
                    if (state.editingAssignmentId === Number(x.assignment_id)) cancelAssignmentEdit();
                    msg('#appMessage', 'Assignment and related data deleted.', true);
                    await refresh();
                } catch (e) {
                    msg('#appMessage', e.message);
                }
            });

            actions.append(edit, del);
            item.append(details, actions);
            al.append(item);
        });

        ['#assignmentCourse', '#taskAssignment'].forEach((sel, i) => {
            const e = $(sel), old = e.value;
            e.replaceChildren();
            (i ? state.assignments : state.courses).forEach(x => option(e, i ? x.assignment_id : x.course_id, i ? x.title : x.course_name));
            if (old) e.value = old;
        });

        const taskOptions = ['#parentTask'];
        taskOptions.forEach(sel => {
            const e = $(sel), old = e.value;
            e.replaceChildren();
            if (sel === '#parentTask') option(e, '', 'No parent — main task');
            state.tasks.forEach(x => option(e, x.task_id, `#${x.task_id} ${x.title}`));
            if (old) e.value = old;
        });

        const tb = $('#taskTable');
        tb.replaceChildren();
        state.tasks.forEach(x => {
            const tr = document.createElement('tr'),
                name = document.createElement('td');
            name.textContent = (x.parent_task_id ? '↳ ' : '') + x.title;

            const ass = document.createElement('td');
            ass.textContent = x.assignment_title;

            const due = document.createElement('td');
            due.textContent = x.due_at || 'Assignment deadline';

            const pr = document.createElement('td');
            const risk = x.status !== 'completed' && x.due_at && new Date(x.due_at) < new Date() ? 'Overdue' : (x.priority === 'high' ? 'High priority' : x.priority);
            const badge = document.createElement('span');
            badge.className = 'badge ' + (risk === 'Overdue' ? 'overdue' : x.priority);
            badge.textContent = risk;
            pr.append(badge);

            const st = document.createElement('td'),
                select = document.createElement('select');
            select.className = 'status-select';
            ['pending', 'in_progress', 'completed'].forEach(v => option(select, v, v.replace('_', ' ')));
            select.value = x.status;
            select.addEventListener('change', async () => {
                try {
                    await api(`tasks/${x.task_id}/status`, {
                        method: 'PATCH',
                        body: JSON.stringify({ status: select.value })
                    });
                    msg('#appMessage', 'Task status updated.', true);
                    refresh();
                } catch (e) {
                    msg('#appMessage', e.message);
                    select.value = x.status;
                }
            });
            st.append(select);
            tr.append(name, ass, due, pr, st);
            tb.append(tr);
        });

        const pl = $('#plannerList');
        pl.replaceChildren();
        p.items.forEach(x => li(pl, x.title, `${x.course_name} · ${x.assignment_title} · ${x.due_at || 'No task date'} · ${x.status}`));

        const calel = $('#calendarList');
        calel.replaceChildren();
        cal.events.forEach(x => {
            const e = document.createElement('div');
            e.className = 'calendar-event';
            const b = document.createElement('strong');
            b.textContent = x.title;
            const s = document.createElement('span');
            s.className = 'item-meta';
            s.textContent = `${x.item_type} · ${x.due_at} · ${x.status}`;
            e.append(b, s);
            calel.append(e);
        });

        const logs = $('#logList');
        logs.replaceChildren();
        l.logs.forEach(x => li(logs, `${x.action} · ${x.task_title}`, `${x.details || ''} · ${x.logged_at}`));

    } catch (e) {
        msg('#appMessage', e.message);
    }
}

document.querySelectorAll('[data-auth-tab]').forEach(b => b.addEventListener('click', () => {
    const reg = b.dataset.authTab === 'register';
    $('#loginForm').classList.toggle('hidden', reg);
    $('#registerForm').classList.toggle('hidden', !reg);
    document.querySelectorAll('[data-auth-tab]').forEach(x => x.classList.toggle('active', x === b));
    msg('#authMessage', '');
}));

$('#loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        const d = await api('auth/login', { method: 'POST', body: JSON.stringify(formData(e.currentTarget)) });
        showApp(d.user);
    } catch (x) {
        msg('#authMessage', x.message);
    }
});

$('#registerForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        const d = await api('auth/register', { method: 'POST', body: JSON.stringify(formData(e.currentTarget)) });
        showApp(d.user);
    } catch (x) {
        msg('#authMessage', x.message);
    }
});

$('#logoutButton').addEventListener('click', async () => {
    try {
        await api('auth/logout', { method: 'POST', body: '{}' });
        showAuth();
    } catch (e) {
        msg('#appMessage', e.message);
    }
});

$('#refreshButton').addEventListener('click', refresh);
$('#adminRefreshButton').addEventListener('click', loadStudents);

[['taskForm', 'tasks', 'Task created.']].forEach(([id, path, text]) =>
    $('#' + id).addEventListener('submit', async e => {
        e.preventDefault();
        const form = e.currentTarget;
        try {
            await api(path, { method: 'POST', body: JSON.stringify(formData(form)) });
            form.reset();
            msg('#appMessage', text, true);
            refresh();
        } catch (x) {
            msg('#appMessage', x.message);
        }
    })
);

$('#assignmentForm').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const data = formData(form);
    const editing = state.editingAssignmentId;
    try {
        if (editing) {
            await api('assignments/' + editing, { method: 'PATCH', body: JSON.stringify(data) });
            cancelAssignmentEdit();
            msg('#appMessage', 'Assignment updated.', true);
        } else {
            await api('assignments', { method: 'POST', body: JSON.stringify(data) });
            form.reset();
            msg('#appMessage', 'Assignment created.', true);
        }
        await refresh();
    } catch (x) {
        msg('#appMessage', x.message);
    }
});

$('#cancelAssignmentEdit').addEventListener('click', cancelAssignmentEdit);

$('#courseForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (state.savingCourse) return;
    state.savingCourse = true;
    const submitButton = $('#courseSubmitButton');
    submitButton.disabled = true;
    const form = e.currentTarget;
    const data = formData(form);
    const editing = state.editingCourseId;
    try {
        if (editing) {
            await api('courses/' + editing, { method: 'PATCH', body: JSON.stringify(data) });
            cancelCourseEdit();
            msg('#appMessage', 'Course updated.', true);
        } else {
            await api('courses', { method: 'POST', body: JSON.stringify(data) });
            form.reset();
            msg('#appMessage', 'Course created.', true);
        }
        await refresh();
    } catch (x) {
        msg('#appMessage', x.message);
    } finally {
        state.savingCourse = false;
        submitButton.disabled = false;
    }
});

$('#cancelCourseEdit').addEventListener('click', cancelCourseEdit);

$('#deleteAllCourses').addEventListener('click', async () => {
    const total = state.courses.length;
    if (total === 0) {
        msg('#appMessage', 'There are no courses to delete.');
        return;
    }
    if (!confirm(`You are about to delete ${total} courses and all their related data. Continue?`)) return;
    if (!confirm(`FINAL CONFIRMATION: Permanently delete all ${total} courses? This cannot be undone.`)) return;
    try {
        const result = await api('courses', { method: 'DELETE', body: '{}' });
        cancelCourseEdit();
        msg('#appMessage', `${result.deleted_count} courses and their related data deleted.`, true);
        await refresh();
    } catch (e) {
        msg('#appMessage', e.message);
    }
});

(async () => {
    try {
        const d = await api('auth/me');
        state.csrfToken = d.csrfToken;
        if (d.user) showApp(d.user);
        else showAuth();
    } catch (e) {
        showAuth();
        msg('#authMessage', e.message);
    }
})();

// Show / hide password toggle
document.querySelectorAll('.toggle-password').forEach(function (btn) {

    btn.addEventListener('click', function () {

        const input = btn.parentElement.querySelector('input');

        const showing = input.type === 'text';

        input.type = showing ? 'password' : 'text';

        btn.textContent = showing ? '👁' : '🙈';

        btn.setAttribute(
            'aria-label',
            showing ? 'Show password' : 'Hide password'
        );

        input.focus();
    });

});