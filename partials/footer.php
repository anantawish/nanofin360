</main>
</div>
<footer class="app-footer">
    <div>
        <div class="footer-copyright">
    <div class="footer-text">
        <strong>NanoFin360 v 1.03</strong> | Copyright 2025 by <strong>Anantawish Anankijareankul</strong> 
        (<a href="mailto:anantawish@gmail.com">anantawish@gmail.com</a>)
        <br>
        <span style="color: #DB4437; font-weight: 600;">Disclaimer:</span> 
        Use of this software is the user's responsibility. The developer is not liable for damages or financial data loss.
    </div>
</div>
    </div>
    <div>
        User: <?php echo h(current_user_name()); ?> (<?php echo h(thai_role_label(current_role_name())); ?>) | Time: <?php echo h(now_dt()); ?>
    </div>
</footer>
<div id="ocrProgressOverlay" class="ocr-progress-overlay" aria-hidden="true">
    <div class="ocr-progress-card" role="status" aria-live="polite">
        <div class="ocr-progress-title">Processing OCR documents</div>
        <div class="ocr-progress-subtitle">The system is reading bank statement documents.</div>
        <div class="progress" style="height: 14px;">
            <div
                id="ocrProgressBar"
                class="progress-bar progress-bar-striped progress-bar-animated"
                role="progressbar"
                style="width: 0%"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="0"
            >0%</div>
        </div>
        <div id="ocrProgressHint" class="ocr-progress-note">Please wait and do not close this page.</div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<?php
$appJsVersion = @filemtime(__DIR__ . '/../assets/js/app.js');
if ($appJsVersion === false) {
    $appJsVersion = time();
}
?>
<script src="<?php echo h(app_base_url('assets/js/app.js?v=' . (string)$appJsVersion)); ?>"></script>
</body>
</html>
