/* ============================================================
   QuizArena — Quiz attempt engine
   Countdown timer, autosave, palette, progress, submit.
   ============================================================ */
(function () {
    'use strict';

    var cfg = window.QA_QUIZ;
    if (!cfg) return;

    var state = {
        questions: cfg.questions,
        answers: cfg.answers || {},      // qid -> option id ('' = unanswered)
        current: 0,
        remaining: cfg.remaining,
        serverTime: Math.floor(Date.now() / 1000),
        lastSave: 0,
        submitting: false
    };

    var timerEl = document.getElementById('timer');
    var progressBar = document.getElementById('progressBar');
    var saveStatus = document.getElementById('saveStatus');
    var questionArea = document.getElementById('questionArea');
    var palette = document.getElementById('palette');
    var curQ = document.getElementById('curQ');

    /* ---------- render ---------- */
    function renderQuestion() {
        var q = state.questions[state.current];
        if (!q) return;
        curQ.textContent = state.current + 1;

        var html = '<h5 class="fw-bold mb-3"><span class="badge text-bg-primary me-2">Q' + (state.current + 1) + '</span>' + esc(q.text) + '</h5>';
        html += '<div class="mb-3" id="optionsBox">';
        q.options.forEach(function (o) {
            var selected = (state.answers[q.id] == o.id);
            html += '<label class="q-option ' + (selected ? 'selected' : '') + '" data-oid="' + o.id + '">'
                + '<span class="q-option-letter">' + o.label + '</span>'
                + '<span>' + esc(o.text) + '</span>'
                + '<input type="radio" name="answer" value="' + o.id + '" ' + (selected ? 'checked' : '') + '>'
                + '</label>';
        });
        html += '</div>';

        questionArea.innerHTML = html;

        // option click → select + autosave
        questionArea.querySelectorAll('.q-option').forEach(function (el) {
            el.addEventListener('click', function () {
                selectOption(parseInt(el.getAttribute('data-oid'), 10));
            });
        });

        document.getElementById('prevBtn').disabled = state.current === 0;
        document.getElementById('nextBtn').textContent = state.current === state.questions.length - 1 ? 'Review' : 'Next';
        updatePalette();
        updateProgress();
    }

    function selectOption(oid) {
        var q = state.questions[state.current];
        state.answers[q.id] = oid;
        renderQuestion();
        saveNow('option');
    }

    function unsetAnswer(qid) {
        delete state.answers[qid];
        state.answers[qid] = '';
        saveNow('unset');
    }

    function updatePalette() {
        var html = '';
        state.questions.forEach(function (q, i) {
            var cls = 'q-num';
            if (state.answers[q.id]) cls += ' answered';
            if (i === state.current) cls += ' current';
            html += '<button type="button" class="' + cls + '" data-idx="' + i + '">' + (i + 1) + '</button>';
        });
        palette.innerHTML = html;
        palette.querySelectorAll('.q-num').forEach(function (b) {
            b.addEventListener('click', function () {
                state.current = parseInt(b.getAttribute('data-idx'), 10);
                renderQuestion();
            });
        });
        // progress
        var answered = state.questions.filter(function (q) { return state.answers[q.id]; }).length;
        var pct = state.questions.length ? (answered / state.questions.length * 100) : 0;
        progressBar.style.width = pct + '%';
        document.getElementById('modalAnswered').textContent = answered;
        document.getElementById('modalUnanswered').textContent = state.questions.length - answered;
        document.getElementById('modalUnansweredWrap').style.display = (state.questions.length - answered) > 0 ? '' : 'none';
    }

    function updateProgress() {
        var answered = state.questions.filter(function (q) { return state.answers[q.id]; }).length;
        var pct = state.questions.length ? (answered / state.questions.length * 100) : 0;
        progressBar.style.width = pct + '%';
    }

    function nav(dir) {
        var next = state.current + dir;
        if (next < 0 || next >= state.questions.length) return;
        state.current = next;
        renderQuestion();
    }

    /* ---------- timer ---------- */
    function tick() {
        state.remaining--;
        if (state.remaining <= 0) {
            timerEl.textContent = '00:00';
            timerEl.classList.add('danger');
            autoSubmit();
            return;
        }
        var m = Math.floor(state.remaining / 60);
        var s = state.remaining % 60;
        timerEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        if (state.remaining <= 60) timerEl.classList.add('danger');
        // periodic autosave every 20s
        if (Date.now() - state.lastSave > 20000) saveNow('autosave');
    }

    /* ---------- save ---------- */
    function buildPayload() {
        var data = {};
        Object.keys(state.answers).forEach(function (qid) {
            var v = state.answers[qid];
            if (v === '' || v === null || v === undefined) data[qid] = '';
            else data[qid] = v;
        });
        return data;
    }

    function saveNow(reason) {
        state.lastSave = Date.now();
        setSaveStatus('<i class="bi bi-cloud-arrow-up"></i> Saving…', 'text-white-50');
        fetch(url('api/quiz_api.php?action=save'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cfg.csrf },
            body: 'att=' + cfg.att + '&answers=' + encodeURIComponent(JSON.stringify(buildPayload()))
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.expired) { autoSubmit(); return; }
            setSaveStatus('<i class="bi bi-cloud-check"></i> Saved ' + new Date().toLocaleTimeString(), 'text-success');
        }).catch(function () {
            setSaveStatus('<i class="bi bi-cloud-slash"></i> Offline — will retry', 'text-warning');
        });
    }

    function setSaveStatus(html, cls) {
        if (saveStatus) { saveStatus.innerHTML = html; saveStatus.className = 'small ' + cls + ' d-none d-sm-inline'; }
    }

    /* ---------- submit ---------- */
    function openSubmitModal() {
        if (state.submitting) return;
        updatePalette();
        var modal = new bootstrap.Modal(document.getElementById('submitModal'));
        modal.show();
    }

    function finalSubmit() {
        if (state.submitting) return;
        state.submitting = true;
        var btn = document.getElementById('finalSubmitBtn');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting…';
        fetch(url('api/quiz_api.php?action=submit'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cfg.csrf },
            body: 'att=' + cfg.att + '&answers=' + encodeURIComponent(JSON.stringify(buildPayload()))
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok && res.redirect) { window.location.href = res.redirect; }
            else { alert(res.error || 'Submission failed. Please try again.'); state.submitting = false; btn.disabled = false; btn.innerHTML = 'Submit Now'; }
        }).catch(function () {
            alert('Network error — please check your connection and try again.'); state.submitting = false; btn.disabled = false; btn.innerHTML = 'Submit Now';
        });
    }

    function autoSubmit() {
        if (state.submitting) return;
        state.submitting = true;
        timerEl.textContent = '00:00';
        fetch(url('api/quiz_api.php?action=submit'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cfg.csrf },
            body: 'att=' + cfg.att + '&answers=' + encodeURIComponent(JSON.stringify(buildPayload()))
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.redirect) window.location.href = res.redirect;
            else window.location.reload();
        }).catch(function () {
            // Server-side timeout finalization will catch it on next load
            window.location.reload();
        });
    }

    function url(p) {
        var base = '';
        try { base = document.querySelector('meta[name="base-url"]') ? document.querySelector('meta[name="base-url"]').getAttribute('content') : ''; } catch (e) {}
        // relative from current directory (quiz/)
        var dir = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        var up = dir.substring(0, dir.lastIndexOf('/'));
        return up + '/' + p;
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ---------- init ---------- */
    renderQuestion();
    setInterval(tick, 1000);

    // warn before closing with in-progress attempt
    window.addEventListener('beforeunload', function (e) {
        if (state.submitting) return;
        saveNow('unload');
        e.preventDefault();
        e.returnValue = 'Your quiz is still in progress. Leaving now will pause it — you can resume later.';
    });

    // expose for inline handlers
    window.nav = nav;
    window.openSubmitModal = openSubmitModal;
    window.finalSubmit = finalSubmit;
})();
