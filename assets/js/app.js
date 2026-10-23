// Ask before submitting forms that delete things
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// Printable pages open the print dialog by themselves once everything has loaded
if (document.body && document.body.hasAttribute('data-autoprint')) {
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
}

// Lists: tap or click anywhere on a row to open it.
// Links and buttons inside the row (Record session, Edit) still do their own thing,
// and Ctrl/Cmd-click opens the row in a new tab.
document.querySelectorAll('tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a, button, input, select, label, summary, form')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return; // selecting text
        if (e.ctrlKey || e.metaKey) {
            window.open(row.dataset.href, '_blank');
        } else {
            window.location.href = row.dataset.href;
        }
    });
});

// Student form: show the chosen photo as a square before it is saved
(function () {
    var input = document.getElementById('photo-input');
    var preview = document.getElementById('photo-preview');
    if (!input || !preview) return;
    var original = preview.innerHTML;
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) { preview.innerHTML = original; return; }
        var img = document.createElement('img');
        img.className = 'photo-2x2';
        img.alt = 'Preview of the chosen photo';
        img.src = URL.createObjectURL(file);
        preview.innerHTML = '';
        preview.appendChild(img);
        var remove = document.getElementById('photo-remove');
        if (remove) remove.checked = false;
    });
})();

// Print buttons
document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
});

// Session form: search for a student and pick them from the list that appears
(function () {
    var picker = document.getElementById('student-picker');
    if (!picker) return;

    var input  = document.getElementById('student-search');
    var list   = document.getElementById('student-results');
    var hidden = document.getElementById('student-id');
    var picked = document.getElementById('student-picked');
    var combo  = document.getElementById('student-combo');
    var change = document.getElementById('student-change');
    var error  = document.getElementById('student-error');
    var lookup = picker.dataset.lookup;
    var items = [];
    var active = -1;
    var timer = null;

    function open() {
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }
    function message(text) {
        list.innerHTML = '';
        var li = document.createElement('li');
        li.className = 'combo-note';
        li.textContent = text;
        list.appendChild(li);
        items = [];
        open();
    }
    function highlight() {
        var options = list.querySelectorAll('[role="option"]');
        options.forEach(function (el, i) {
            el.setAttribute('aria-selected', i === active ? 'true' : 'false');
            if (i === active) {
                input.setAttribute('aria-activedescendant', el.id);
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }
    function render(data, query) {
        items = data.results || [];
        if (!items.length) {
            message('No students match “' + query + '”.');
            return;
        }
        list.innerHTML = '';
        items.forEach(function (s, i) {
            var li = document.createElement('li');
            li.id = 'student-option-' + i;
            li.setAttribute('role', 'option');
            var name = document.createElement('strong');
            name.textContent = s.name;
            var detail = document.createElement('span');
            detail.textContent = s.no + (s.detail ? ', ' + s.detail : '') + (s.middle ? ' (middle name ' + s.middle + ')' : '');
            li.appendChild(name);
            li.appendChild(detail);
            li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
            list.appendChild(li);
        });
        if (data.total > items.length) {
            var more = document.createElement('li');
            more.className = 'combo-note';
            more.textContent = 'Showing ' + items.length + ' of ' + data.total + '. Keep typing to narrow it down.';
            list.appendChild(more);
        }
        active = 0;
        open();
        highlight();
    }
    function search() {
        var query = input.value.trim();
        if (query === '') { close(); return; }
        fetch(lookup + '?q=' + encodeURIComponent(query), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (input.value.trim() !== query) return; // the counselor kept typing
                if (data.error) { message(data.error); return; }
                render(data, query);
            })
            .catch(function () { message('The search did not work. Refresh the page and try again.'); });
    }
    function choose(i) {
        var s = items[i];
        if (!s) return;
        hidden.value = s.id;
        picked.querySelector('.picked-name').textContent = s.name;
        picked.querySelector('.picked-detail').textContent = s.no + (s.detail ? ', ' + s.detail : '');
        picked.hidden = false;
        combo.hidden = true;
        error.hidden = true;
        close();
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 200);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && items.length) {
            e.preventDefault();
            if (list.hidden) open();
            active = Math.min(active + 1, items.length - 1);
            highlight();
        } else if (e.key === 'ArrowUp' && items.length) {
            e.preventDefault();
            active = Math.max(active - 1, 0);
            highlight();
        } else if (e.key === 'Enter') {
            e.preventDefault(); // Enter picks a student; it never submits the form from here
            if (!list.hidden && active >= 0) {
                choose(active);
            } else {
                clearTimeout(timer);
                search();
            }
        } else if (e.key === 'Escape') {
            close();
        }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
    input.addEventListener('focus', function () { if (input.value.trim() !== '' && items.length) open(); });

    change.addEventListener('click', function () {
        hidden.value = '';
        picked.hidden = true;
        combo.hidden = false;
        input.value = '';
        items = [];
        input.focus();
    });

    picker.closest('form').addEventListener('submit', function (e) {
        if (!hidden.value) {
            e.preventDefault();
            picked.hidden = true;
            combo.hidden = false;
            error.hidden = false;
            input.focus();
        }
    });
})();

// Student form: the Section choices follow the chosen grade level.
// If a grade has no sections set yet, a text box is shown instead.
(function () {
    var grade = document.getElementById('grade-select');
    var select = document.getElementById('section-select');
    var text = document.getElementById('section-text');
    var hint = document.getElementById('section-hint');
    if (!grade || !select || !text) return;

    var sections = {};
    try { sections = JSON.parse(select.dataset.sections || '{}'); } catch (e) {}

    grade.addEventListener('change', function () {
        var current = (select.disabled ? text.value : select.value).trim();
        var list = sections[grade.value] || [];

        if (list.length) {
            select.innerHTML = '';
            select.appendChild(new Option('', ''));
            var found = false;
            list.forEach(function (name) {
                var isCurrent = name.toLowerCase() === current.toLowerCase();
                if (isCurrent) found = true;
                select.appendChild(new Option(name, name, isCurrent, isCurrent));
            });
            if (!found) select.value = '';
            select.hidden = select.disabled = false;
            text.hidden = text.disabled = true;
            hint.textContent = 'Sections for the chosen grade.';
        } else {
            text.value = current;
            text.hidden = text.disabled = false;
            select.hidden = select.disabled = true;
            hint.textContent = grade.value ? 'No sections set for this grade yet, so type it in.' : 'Choose the grade level first, or type the section.';
        }
    });
})();

// Show the follow-up date only when the status is "Follow-up"
(function () {
    var field = document.getElementById('follow-up-field');
    if (!field) return;
    var radios = document.querySelectorAll('input[name="status"]');
    var input = field.querySelector('input');

    function update() {
        var checked = document.querySelector('input[name="status"]:checked');
        var isFollowUp = checked && checked.value === 'Follow-up';
        field.hidden = !isFollowUp;
        input.required = isFollowUp;
    }
    radios.forEach(function (r) { r.addEventListener('change', update); });
    update();
})();
