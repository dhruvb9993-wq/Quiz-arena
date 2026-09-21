/* ============================================================
   QuizArena — Global UI helpers
   ============================================================ */
(function () {
    'use strict';

    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            var bs = window.bootstrap && bootstrap.Alert;
            if (bs) { var a = bs.getOrCreateInstance(el); if (a) a.close(); }
        }, 5000);
    });

    // Mobile sidebar backdrop
    var sb = document.getElementById('dashSidebar');
    var backdrop = document.querySelector('.dash-sidebar-backdrop');
    if (sb && backdrop) {
        var obs = new MutationObserver(function () {
            backdrop.classList.toggle('show', sb.classList.contains('open'));
        });
        obs.observe(sb, { attributes: true, attributeFilter: ['class'] });
        backdrop.addEventListener('click', function () { sb.classList.remove('open'); });
    }

    // data-confirm: attach to forms
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm');
            if (!window.confirm(msg)) e.preventDefault();
        });
    });

    // data-confirm on links
    document.querySelectorAll('a[data-confirm]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (!window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    // Password visibility toggles
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-target'));
            if (!target) return;
            if (target.type === 'password') { target.type = 'text'; btn.classList.replace('bi-eye', 'bi-eye-slash'); }
            else { target.type = 'password'; btn.classList.replace('bi-eye-slash', 'bi-eye'); }
        });
    });

    // Copy to clipboard
    document.querySelectorAll('[data-copy]').forEach(function (el) {
        el.addEventListener('click', function () {
            var text = el.getAttribute('data-copy');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var old = el.innerHTML;
                    el.innerHTML = '<i class="bi bi-check2"></i> Copied';
                    setTimeout(function () { el.innerHTML = old; }, 1500);
                });
            }
        });
    });

    // Live username hint formatting (@name)
    document.querySelectorAll('input[data-username]').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var v = inp.value.trim();
            if (v.charAt(0) !== '@') { inp.value = '@' + v.replace(/^@+/, ''); }
        });
    });

    window.QA = {
        csrf: function () {
            var m = document.querySelector('meta[name="csrf"]');
            return m ? m.getAttribute('content') : '';
        },
        ajax: function (url, opts) {
            opts = opts || {};
            opts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': QA.csrf() }, opts.headers || {});
            opts.credentials = 'same-origin';
            return fetch(url, opts).then(function (res) {
                return res.json().catch(function () { return { ok: false, error: 'Invalid server response' }; });
            });
        },
        post: function (url, data) {
            var fd = new FormData();
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
            return QA.ajax(url, { method: 'POST', body: fd });
        }
    };
})();
