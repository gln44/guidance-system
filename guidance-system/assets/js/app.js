// Ask before submitting forms that delete things
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// Print button on the session record
document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
});

// Narrow the student dropdown as the counselor types
(function () {
    var filter = document.getElementById('student-filter');
    var select = document.getElementById('student-select');
    if (!filter || !select) return;

    var options = Array.prototype.slice.call(select.options, 1); // skip "Choose a student"

    filter.addEventListener('input', function () {
        var term = filter.value.trim().toLowerCase();
        var matches = [];
        options.forEach(function (opt) {
            var show = term === '' || opt.text.toLowerCase().indexOf(term) !== -1;
            opt.hidden = !show;
            opt.disabled = !show;
            if (show) matches.push(opt);
        });
        // If only one student matches, select them
        if (matches.length === 1) {
            select.value = matches[0].value;
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
