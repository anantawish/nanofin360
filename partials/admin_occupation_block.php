<?php
declare(strict_types=1);
?>
<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1">Occupation Master by Province</h2>
                <div class="text-muted small">Includes 60 default occupations (30 government/private + 30 agriculture), with soft-delete add/edit/delete support.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#occupationCreateModal">Add Occupation</button>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#occupationListModal">View Occupation List</button>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <div class="stat-card">
                    <span>Active Occupations</span>
                    <strong><?php echo number_format($activeOccupationCount); ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <span>Active Agriculture Occupations</span>
                    <strong><?php echo number_format($agricultureOccupationCount); ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <span>Number of provinces supported</span>
                    <strong><?php echo number_format($provinceCount); ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <span>Income conditions</span>
                    <strong class="fs-6">Minimum <= Average <= Maximum</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="occupationCreateModal" tabindex="-1" aria-labelledby="occupationCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="occupation_create">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="occupationCreateModalLabel">Add Occupation</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Occupation code *</label>
                            <input class="form-control" name="occupation_code" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Occupation Name *</label>
                            <input class="form-control" name="occupation_name" maxlength="200" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" id="occupation_create_type" required>
                                <?php foreach ($occupationTypeOptions as $typeKey => $typeLabel): ?>
                                    <option value="<?php echo h((string)$typeKey); ?>"><?php echo h((string)$typeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Province *</label>
                            <select class="form-select" name="province_name" required>
                                <option value="">-- Select province --</option>
                                <?php foreach ($thaiProvinces as $province): ?>
                                    <option value="<?php echo h($province); ?>"><?php echo h($province); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Minimum Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_min" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Average Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_default" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maximum Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_max" step="0.01" min="0" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Agriculture Details (required when Employment Type is Agriculture)</label>
                            <textarea class="form-control" name="agriculture_detail" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="note_text" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Occupation</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="occupationListModal" tabindex="-1" aria-labelledby="occupationListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="occupationListModalLabel">List of occupations by province</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0 js-admin-datatable">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Code</th>
                            <th>Occupation Name</th>
                            <th>Employment Type</th>
                            <th>Province</th>
                            <th>Minimum Income</th>
                            <th>Average Income</th>
                            <th>Maximum Income</th>
                            <th>Agriculture Details</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($occupationRows as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><code><?php echo h((string)$row['occupation_code']); ?></code></td>
                                <td><?php echo h((string)$row['occupation_name']); ?></td>
                                <td><?php echo h(admin_employment_type_label((string)$row['employment_type'])); ?></td>
                                <td><?php echo h((string)$row['province_name']); ?></td>
                                <td><?php echo number_format((float)$row['avg_income_min'], 2); ?></td>
                                <td><?php echo number_format((float)$row['avg_income_default'], 2); ?></td>
                                <td><?php echo number_format((float)$row['avg_income_max'], 2); ?></td>
                                <td class="small"><?php echo h((string)$row['agriculture_detail']); ?></td>
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
                                                class="btn btn-sm btn-outline-primary js-edit-occupation-btn"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#occupationEditModal"
                                                data-source-id="<?php echo (int)$row['id']; ?>"
                                                data-occupation-code="<?php echo h((string)$row['occupation_code']); ?>"
                                                data-occupation-name="<?php echo h((string)$row['occupation_name']); ?>"
                                                data-employment-type="<?php echo h((string)$row['employment_type']); ?>"
                                                data-province-name="<?php echo h((string)$row['province_name']); ?>"
                                                data-avg-income-min="<?php echo h((string)$row['avg_income_min']); ?>"
                                                data-avg-income-default="<?php echo h((string)$row['avg_income_default']); ?>"
                                                data-avg-income-max="<?php echo h((string)$row['avg_income_max']); ?>"
                                                data-agri-detail="<?php echo h((string)$row['agriculture_detail']); ?>"
                                                data-note-text="<?php echo h((string)$row['note_text']); ?>"
                                            >Edit</button>
                                            <form method="post" class="needs-confirm-delete">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_action" value="occupation_delete">
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

<div class="modal fade" id="occupationEditModal" tabindex="-1" aria-labelledby="occupationEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="occupation_update">
                <input type="hidden" name="source_id" id="occupation_edit_source_id">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="occupationEditModalLabel">Edit Occupation</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Occupation code *</label>
                            <input class="form-control" name="occupation_code" id="occupation_edit_code" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Occupation Name *</label>
                            <input class="form-control" name="occupation_name" id="occupation_edit_name" maxlength="200" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" id="occupation_edit_type" required>
                                <?php foreach ($occupationTypeOptions as $typeKey => $typeLabel): ?>
                                    <option value="<?php echo h((string)$typeKey); ?>"><?php echo h((string)$typeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Province *</label>
                            <select class="form-select" name="province_name" id="occupation_edit_province" required>
                                <option value="">-- Select province --</option>
                                <?php foreach ($thaiProvinces as $province): ?>
                                    <option value="<?php echo h($province); ?>"><?php echo h($province); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Minimum Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_min" id="occupation_edit_income_min" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Average Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_default" id="occupation_edit_income_default" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maximum Income (THB/month) *</label>
                            <input class="form-control" type="number" name="avg_income_max" id="occupation_edit_income_max" step="0.01" min="0" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Agriculture Details (required when Employment Type is Agriculture)</label>
                            <textarea class="form-control" name="agriculture_detail" id="occupation_edit_agri_detail" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="note_text" id="occupation_edit_note" rows="2" maxlength="500"></textarea>
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
