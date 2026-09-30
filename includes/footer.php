<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/footer.php
 *
 * Closes the shared application shell and loads global scripts.
 */

declare(strict_types=1);
?>
            </div><!-- /.content-inner -->
        </main>

        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> <?= e(FLEETRA_NAME) ?> &middot; <?= e(FLEETRA_TAGLINE) ?></span>
            <span class="app-footer__version">v<?= e(FLEETRA_VERSION) ?></span>
        </footer>
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<?php
/*
 * Shared confirmation dialog for destructive actions.
 * Any form can opt in with:
 *   data-confirm="Delete bus FLT-105?"
 *   data-confirm-title="Delete bus?"  data-confirm-button="Delete bus"
 *   data-confirm-variant="primary"    (defaults to the danger style)
 */
?>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Are you sure?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirmModalBody" style="color:var(--fl-text-muted);font-size:14px;">
                    This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>

<?php foreach ($extra_js as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
