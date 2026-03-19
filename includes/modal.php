<?php
// Dynamic modal based on passed parameters
$modal_id = $modal_id ?? 'seedModal';
$modal_title = $modal_title ?? 'Add Item';
$modal_size = $modal_size ?? 'medium'; // small, medium, large
?>

<!-- Reusable Modal Component -->
<div id="<?= $modal_id ?>" class="modal modal-<?= $modal_size ?>" data-modal-type="<?= $modal_type ?? 'default' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="<?= $modal_icon ?? 'fas fa-plus-circle' ?>"></i> <?= $modal_title ?></h3>
            <span class="close-modal" data-close="<?= $modal_id ?>">&times;</span>
        </div>
        <div class="modal-body" id="<?= $modal_id ?>_body">
            <!-- Dynamic content will be loaded here -->
            <div class="modal-loader" style="display:none; text-align:center; padding:20px;">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn modal-btn-secondary" data-close="<?= $modal_id ?>">Cancel</button>
            <button type="button" class="modal-btn modal-btn-primary" id="<?= $modal_id ?>_submit">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- Modal CSS (include once) -->
<style>
/* Modal Styles - Copy from previous response */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fff;
    margin: 5% auto;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease;
}

.modal-small .modal-content { width: 90%; max-width: 400px; }
.modal-medium .modal-content { width: 90%; max-width: 500px; }
.modal-large .modal-content { width: 90%; max-width: 800px; }

/* Rest of the styles from previous response */
</style>