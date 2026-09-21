<script>
/* =========================================================
   MODAL HELPERS
========================================================= */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.classList.add('no-scroll');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(bd => closeModal(bd.id));
    }
});

/* =========================================================
   HELPERS
========================================================= */
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

/* =========================================================
   VIEW TEACHER
========================================================= */
let lastViewedTeacherId = 0;

function viewTeacher(id) {
    lastViewedTeacherId = id;

    const body = document.getElementById('viewTeacherBody');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    openModal('viewTeacherModal');

    fetch('get_teacher.php?action=view&id=' + id, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Failed');
        const t = data.teacher;

        const photo = t.photo_url
            ? `<img src="${escHtml(t.photo_url)}" alt="" class="view-photo"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
               <div class="view-photo-placeholder" style="display:none;">${escHtml(t.initial)}</div>`
            : `<div class="view-photo-placeholder">${escHtml(t.initial)}</div>`;

        /* Chips */
        let chips = `<span class="view-chip gold">${escHtml(t.role_label)}</span>`;
        if (t.class_teacher_of) {
            chips += `<span class="view-chip green">Class Teacher · ${escHtml(t.class_teacher_of)}</span>`;
        }
        chips += `<span class="view-chip ${t.employment_status === 'active' ? 'green' : 'muted'}">
                    ${escHtml(t.employment_status.charAt(0).toUpperCase() + t.employment_status.slice(1))}
                  </span>`;

        /* Classes */
        const classesHTML = (t.classes && t.classes.length)
            ? `<div class="view-pills">${t.classes.map(c =>
                `<span class="view-pill">${escHtml(c.label)}</span>`).join('')}</div>`
            : `<div class="view-empty">No classes assigned yet.</div>`;

        /* Subjects */
        const subjectsHTML = (t.subjects && t.subjects.length)
            ? `<div class="view-pills">${t.subjects.map(s =>
                `<span class="view-pill subject">${escHtml(s.subject_name)}</span>`).join('')}</div>`
            : `<div class="view-empty">No subjects assigned yet.</div>`;

        body.innerHTML = `
            <div class="view-hero">
                ${photo}
                <div class="view-hero-info">
                    <h3>${escHtml(t.full_name)}</h3>
                    <div class="meta">
                        ${escHtml(t.email || '—')}
                        ${t.employee_no ? ' · ' + escHtml(t.employee_no) : ''}
                    </div>
                    <div class="view-chips">${chips}</div>
                </div>
            </div>

            <div class="view-grid">
                <div class="view-item">
                    <div class="k">Gender</div>
                    <div class="v">${escHtml(t.gender || '—')}</div>
                </div>
                <div class="view-item">
                    <div class="k">Phone</div>
                    <div class="v">${escHtml(t.phone || '—')}</div>
                </div>
                <div class="view-item">
                    <div class="k">Qualification</div>
                    <div class="v">${escHtml(t.qualification || '—')}</div>
                </div>
                <div class="view-item">
                    <div class="k">Specialization</div>
                    <div class="v">${escHtml(t.specialization || '—')}</div>
                </div>
                <div class="view-item">
                    <div class="k">Employee No.</div>
                    <div class="v">${escHtml(t.employee_no || '—')}</div>
                </div>
                <div class="view-item">
                    <div class="k">Joined</div>
                    <div class="v">${escHtml(t.joined_fmt || '—')}</div>
                </div>
            </div>

            <div class="view-section">
                <h4>Classes</h4>
                ${classesHTML}
            </div>

            <div class="view-section">
                <h4>Subjects</h4>
                ${subjectsHTML}
            </div>
        `;
    })
    .catch(err => {
        body.innerHTML = `<div style="text-align:center;padding:40px;color:var(--red);">${escHtml(err.message)}</div>`;
    });
}

/* "Edit Teacher" button inside the view modal */
document.getElementById('viewToEditBtn').addEventListener('click', () => {
    if (!lastViewedTeacherId) return;
    closeModal('viewTeacherModal');
    setTimeout(() => editTeacher(lastViewedTeacherId), 150);
});


/* =========================================================
   EDIT TEACHER
========================================================= */
function editTeacher(id) {

    const form = document.getElementById('editTeacherForm');
    form.reset();
    document.getElementById('edit_t_id').value = id;

    openModal('editTeacherModal');

    fetch('get_teacher.php?action=edit&id=' + id, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Failed');
        const t = data.teacher;

        document.getElementById('edit_u_id').value           = t.user_id;
        document.getElementById('edit_first_name').value     = t.first_name  || '';
        document.getElementById('edit_middle_name').value    = t.middle_name || '';
        document.getElementById('edit_last_name').value      = t.last_name   || '';
        document.getElementById('edit_gender').value         = t.gender      || 'Male';
        document.getElementById('edit_email').value          = t.email       || '';
        document.getElementById('edit_phone').value          = t.phone       || '';
        document.getElementById('edit_employee_no').value    = t.employee_no || '';
        document.getElementById('edit_qualification').value  = t.qualification || '';
        document.getElementById('edit_specialization').value = t.specialization || '';
        document.getElementById('edit_employment_status').value = t.employment_status || 'active';
        document.getElementById('edit_user_status').value       = t.user_status || 'active';
    })
    .catch(err => {
        closeModal('editTeacherModal');
        alert(err.message);
    });
}

/* Submit edit form */
document.getElementById('editTeacherForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const btn = document.getElementById('saveTeacherBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    const fd = new FormData(this);

    fetch('update_teacher.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Update failed');

        /* Update the row + card in place */
        if (data.teacher) {
            updateTeacherRow(data.teacher);
            updateTeacherCard(data.teacher);
        }

        closeModal('editTeacherModal');
        alert('Teacher updated successfully.');
    })
    .catch(err => alert(err.message))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
});


/* =========================================================
   IN-PLACE ROW UPDATE
========================================================= */
function updateTeacherRow(t) {
    const tr = document.querySelector(`tr[data-teacher-id="${t.teacher_id}"]`);
    if (!tr) return;

    /* Name + initial */
    const nameEl = tr.querySelector('.teacher-name');
    if (nameEl) nameEl.textContent = t.full_name;

    /* Email */
    const emailEl = tr.querySelector('.email');
    if (emailEl) emailEl.textContent = t.email || '—';

    /* Phone */
    const phoneEl = tr.querySelector('.phone');
    if (phoneEl) phoneEl.textContent = t.phone || '—';

    /* Employee */
    const empEl = tr.querySelector('.employee-number');
    if (empEl) empEl.textContent = t.employee_no || '—';

    /* Qualification */
    const qualEl = tr.querySelector('.qualification');
    if (qualEl) qualEl.textContent = t.qualification || 'Not specified';

    /* Specialization */
    const specEl = tr.querySelector('.specialization');
    if (specEl) specEl.textContent = t.specialization || 'Not specified';

    /* Status */
    const statusEl = tr.querySelector('.status');
    if (statusEl) {
        statusEl.className = 'status status-' + (t.employment_status || '').toLowerCase();
        statusEl.textContent = (t.employment_status || '').charAt(0).toUpperCase() + (t.employment_status || '').slice(1);
    }
}

function updateTeacherCard(t) {
    const card = document.querySelector(`.teacher-card[data-teacher-id="${t.teacher_id}"]`);
    if (!card) return;

    const nameEl = card.querySelector('.teacher-name');
    if (nameEl) nameEl.textContent = t.full_name;

    const metaEls = card.querySelectorAll('.teacher-meta');
    if (metaEls[0]) metaEls[0].textContent = t.email || '';
}


/* =========================================================
   DELEGATED CLICK HANDLER
========================================================= */
document.addEventListener('click', e => {
    const viewBtn = e.target.closest('[data-action="view"]');
    const editBtn = e.target.closest('[data-action="edit"]');

    if (viewBtn) {
        const id = viewBtn.dataset.teacherId || new URLSearchParams(viewBtn.dataset.url.split('?')[1]).get('id');
        if (id) viewTeacher(parseInt(id));
    }

    if (editBtn) {
        const id = editBtn.dataset.teacherId || new URLSearchParams(editBtn.dataset.url.split('?')[1]).get('id');
        if (id) editTeacher(parseInt(id));
    }
});
</script>