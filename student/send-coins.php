<?php
/** QuizArena — Student: send Quiz Coins to another user by username */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$balance = (int) ($user['wallet_balance'] ?? 0);

$title = 'Send Quiz Coins';
$active = 'send';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<meta name="csrf" content="<?= e(csrf_token()) ?>">

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-send"></i> Send <?= e(coin_name()) ?></h5>
                    <span class="badge badge-soft-warning"><i class="bi bi-coin"></i> Your balance: <?= fmt_coin($balance) ?></span>
                </div>

                <div id="step1">
                    <label class="form-label small fw-semibold">Receiver Username</label>
                    <div class="input-group mb-3">
                        <span class="input-group-text">@</span>
                        <input class="form-control" id="toUsername" placeholder="e.g. priya_2006" autocomplete="off">
                        <button class="btn btn-primary" id="lookupBtn" onclick="lookupUser()"><i class="bi bi-search"></i> Find</button>
                    </div>
                    <div id="lookupResult"></div>
                </div>

                <div id="step2" class="d-none">
                    <div class="alert alert-light border">
                        <div class="d-flex align-items-center gap-3">
                            <img id="rcvAvatar" class="rounded-circle object-fit-cover border" width="56" height="56" src="" alt="">
                            <div>
                                <div class="fw-bold" id="rcvName">—</div>
                                <div class="fs-8 text-muted" id="rcvMeta">—</div>
                            </div>
                            <button class="btn btn-sm btn-light ms-auto" onclick="resetFlow()"><i class="bi bi-arrow-repeat"></i> Change</button>
                        </div>
                    </div>
                    <label class="form-label small fw-semibold">Amount (<?= e(coin_name()) ?>)</label>
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-coin text-warning"></i></span>
                        <input class="form-control" type="number" min="1" id="amount" placeholder="Enter amount" value="">
                    </div>
                    <div class="alert alert-warning small py-2" id="balanceNote"></div>
                    <button class="btn btn-primary w-100 py-2" onclick="confirmTransfer()"><i class="bi bi-send-check"></i> Review Transfer</button>
                </div>

                <div id="resultMsg" class="d-none mt-3"></div>
            </div>
        </div>

        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">How it works</h5></div>
            <div class="dash-card-body small text-muted">
                <ol class="mb-0 ps-3">
                    <li>Enter the receiver's username (with or without @).</li>
                    <li>We show their profile photo and name to confirm you have the right person.</li>
                    <li>Enter the amount — it must not exceed your wallet balance.</li>
                    <li>Review the confirmation and send. The transfer is immediate and permanent.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4 text-center">
                <div class="display-5 mb-2">🪙</div>
                <h5 class="fw-bold">Confirm Transfer</h5>
                <p class="mb-3 text-muted small">You are sending</p>
                <div class="display-6 fw-bold text-primary mb-3" id="confirmAmount">0</div>
                <p class="small mb-1">to <b id="confirmName">—</b> <span class="text-muted">(@<span id="confirmUsername">—</span>)</span></p>
                <p class="fs-8 text-muted">This cannot be undone. A permanent transaction record will be created for both users.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary px-4" id="sendBtn" onclick="doTransfer()"><i class="bi bi-send"></i> Confirm & Send</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let receiver = null;
    const csrf = document.querySelector('meta[name="csrf"]').getAttribute('content');

    function lookupUser() {
        const uname = document.getElementById('toUsername').value.trim();
        const out = document.getElementById('lookupResult');
        if (!uname) { out.innerHTML = '<div class="alert alert-danger py-2 small">Please enter a username.</div>'; return; }
        out.innerHTML = '<div class="text-center text-muted py-2"><span class="spinner-border spinner-border-sm"></span> Checking…</div>';
        fetch('<?= url('api/wallet_api.php?action=lookup&username=') ?>' + encodeURIComponent(uname), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(d => {
                if (!d.ok) { out.innerHTML = '<div class="alert alert-danger py-2 small">' + esc(d.error) + '</div>'; return; }
                receiver = d.user;
                out.innerHTML = '<div class="alert alert-success py-2 small"><i class="bi bi-check-circle-fill"></i> User found! Continue below.</div>';
                document.getElementById('rcvAvatar').src = d.user.avatar;
                document.getElementById('rcvName').textContent = d.user.name;
                document.getElementById('rcvMeta').textContent = '@' + d.user.username + ' · ' + d.user.school + (d.user.class ? ' · ' + d.user.class : '');
                document.getElementById('step1').classList.add('d-none');
                document.getElementById('step2').classList.remove('d-none');
                updateBalanceNote();
            }).catch(() => { out.innerHTML = '<div class="alert alert-danger py-2 small">Network error. Please try again.</div>'; });
    }

    function updateBalanceNote() {
        document.getElementById('balanceNote').innerHTML = 'You can send up to <b>' + '<?= $balance ?>' + '</b> coins.';
    }

    function confirmTransfer() {
        const amt = parseInt(document.getElementById('amount').value, 10);
        const err = document.getElementById('resultMsg');
        err.classList.add('d-none');
        if (!receiver) { alert('Please find a receiver first.'); return; }
        if (!amt || amt <= 0) { err.className = 'alert alert-danger small py-2 mt-3'; err.textContent = 'Amount must be greater than zero.'; err.classList.remove('d-none'); return; }
        if (amt > <?= $balance ?>) { err.className = 'alert alert-danger small py-2 mt-3'; err.textContent = 'Insufficient balance. You have <?= $balance ?> coins.'; err.classList.remove('d-none'); return; }
        document.getElementById('confirmAmount').textContent = amt;
        document.getElementById('confirmName').textContent = receiver.name;
        document.getElementById('confirmUsername').textContent = receiver.username;
        new bootstrap.Modal(document.getElementById('confirmModal')).show();
    }

    function doTransfer() {
        const amt = parseInt(document.getElementById('amount').value, 10);
        const btn = document.getElementById('sendBtn');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending…';
        const fd = new FormData();
        fd.append('to_username', receiver.username);
        fd.append('amount', amt);
        fetch('<?= url('api/wallet_api.php?action=transfer') ?>', {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
        }).then(r => r.json()).then(d => {
            bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
            const msg = document.getElementById('resultMsg');
            if (d.ok) {
                msg.className = 'alert alert-success py-3 text-center';
                msg.innerHTML = '<i class="bi bi-check-circle-fill fs-3 d-block mb-2"></i><b>' + esc(d.msg) + '</b><br><span class="fs-8">New balance: ' + d.new_balance + ' coins</span>';
                document.getElementById('step2').classList.add('d-none');
                document.getElementById('step1').classList.remove('d-none');
                document.getElementById('toUsername').value = '';
                document.getElementById('amount').value = '';
                receiver = null;
            } else {
                msg.className = 'alert alert-danger small py-2';
                msg.textContent = d.error;
            }
            msg.classList.remove('d-none');
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Confirm & Send';
        }).catch(() => {
            bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
            const msg = document.getElementById('resultMsg');
            msg.className = 'alert alert-danger small py-2'; msg.textContent = 'Network error — your coins were NOT sent. Please check and retry.';
            msg.classList.remove('d-none');
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Confirm & Send';
        });
    }

    function resetFlow() {
        receiver = null;
        document.getElementById('step2').classList.add('d-none');
        document.getElementById('step1').classList.remove('d-none');
        document.getElementById('lookupResult').innerHTML = '';
    }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
</script>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
