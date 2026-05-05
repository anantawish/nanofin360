    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function () {
    const thaiLanguage = {
        emptyTable: "ไม่พบข้อมูล",
        info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
        infoEmpty: "แสดง 0 ถึง 0 จาก 0 รายการ",
        infoFiltered: "(กรองจากทั้งหมด _MAX_ รายการ)",
        zeroRecords: "ไม่พบข้อมูลที่ค้นหา",
        paginate: {
            first: "หน้าแรก",
            last: "หน้าสุดท้าย",
            next: "ถัดไป",
            previous: "ก่อนหน้า"
        }
    };

    $(".js-fresher-datatable").each(function () {
        if ($.fn.DataTable.isDataTable(this)) {
            return;
        }
        $(this).DataTable({
            pageLength: 50,
            lengthChange: false,
            lengthMenu: [[50], [50]],
            order: [],
            language: thaiLanguage
        });
    });

    $(".js-confirm-delete").on("submit", function (event) {
        if (!window.confirm("ยืนยันลบแบบ soft delete ใช่หรือไม่?")) {
            event.preventDefault();
        }
    });

    $("select[name='contract_code']").each(function () {
        const $select = $(this);
        if ($select.hasClass("js-no-contract-search")) {
            return;
        }
        if ($select.data("select2")) {
            return;
        }

        const hasEmptyOption = $select.find("option[value='']").length > 0;
        $select.select2({
            theme: "bootstrap-5",
            width: "100%",
            placeholder: "พิมพ์เลขสัญญาหรือชื่อ-นามสกุลลูกค้า",
            allowClear: hasEmptyOption,
            language: {
                noResults: function () { return "ไม่พบสัญญาที่ค้นหา"; },
                searching: function () { return "กำลังค้นหา..."; },
                inputTooShort: function () { return "พิมพ์เพื่อค้นหา"; }
            }
        });
    });
});
</script>
<?php
$appJsVersion = @filemtime(__DIR__ . '/../../assets/js/app.js');
if ($appJsVersion === false) {
    $appJsVersion = time();
}
?>
<script src="<?php echo h(app_base_url('assets/js/app.js?v=' . (string)$appJsVersion)); ?>"></script>
</body>
</html>
