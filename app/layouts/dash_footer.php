<?php
if (!defined('QA_RUNNING')) exit('Direct access denied');
?>
        </div>
        <footer class="dash-footer">
            <span>© <?= date('Y') ?> <?= e(setting('site_name', 'QuizArena')) ?></span>
        </footer>
    </div>
</div>
<script src="<?= url('assets/vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= url('assets/vendor/chart.umd.js') ?>"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
<?php if (!empty($extra_js)) echo $extra_js; ?>
</body>
</html>
