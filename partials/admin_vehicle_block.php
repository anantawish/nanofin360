<?php
declare(strict_types=1);
?>
<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1">Vehicle Master: Manage Brands and Models</h2>
                <div class="text-muted small">Add, edit, and soft-delete brand/model entries for customer form dropdowns.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#carMasterCreateModal">Add car brand/model</button>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#carMasterListModal">View Brand/Model List</button>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Active Brands</span>
                    <strong><?php echo number_format((int)$activeCarBrandCount); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Active Models</span>
                    <strong><?php echo number_format((int)$activeCarModelCount); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Notes</span>
                    <strong class="fs-6">Vehicle models are automatically displayed by brand.</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="carMasterCreateModal" tabindex="-1" aria-labelledby="carMasterCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="car_master_create">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="carMasterCreateModalLabel">Add car brand/model</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Car brand *</label>
                            <input class="form-control" name="brand_name" maxlength="120" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Car model *</label>
                            <input class="form-control" name="model_name" maxlength="160" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="note_text" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="carMasterListModal" tabindex="-1" aria-labelledby="carMasterListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="carMasterListModalLabel">Vehicle Brand/Model List</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0 js-admin-datatable">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Brand</th>
                            <th>Model</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($carMasterRows as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><?php echo h((string)$row['brand_name']); ?></td>
                                <td><?php echo h((string)$row['model_name']); ?></td>
                                <td><?php echo (int)$row['version_no']; ?></td>
                                <td>
                                    <?php if ((int)$row['is_deleted'] === 1): ?>
                                        <span class="badge text-bg-secondary">Deleted</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ((int)$row['is_deleted'] === 0): ?>
                                            <button
                                                class="btn btn-sm btn-outline-primary js-edit-car-master-btn"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#carMasterEditModal"
                                                data-source-id="<?php echo (int)$row['id']; ?>"
                                                data-brand-name="<?php echo h((string)$row['brand_name']); ?>"
                                                data-model-name="<?php echo h((string)$row['model_name']); ?>"
                                                data-note-text="<?php echo h((string)($row['note_text'] ?? '')); ?>"
                                            >Edit</button>
                                            <form method="post" class="needs-confirm-delete">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_action" value="car_master_delete">
                                                <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="carMasterEditModal" tabindex="-1" aria-labelledby="carMasterEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="car_master_update">
                <input type="hidden" name="source_id" id="car_master_edit_source_id">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="carMasterEditModalLabel">Edit car brand/model</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Car brand *</label>
                            <input class="form-control" name="brand_name" id="car_master_edit_brand_name" maxlength="120" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Car model *</label>
                            <input class="form-control" name="model_name" id="car_master_edit_model_name" maxlength="160" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="note_text" id="car_master_edit_note" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Changes</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
