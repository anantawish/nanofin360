(function () {
    var nanfinUiLang = "en";

    function nanfinContainsMojibake(text) {
        if (typeof text !== "string" || text === "") {
            return false;
        }
        return /(?:\u00C3|\u00C2|\u00E0[\u00B8\u00B9\u00BA]|\u00E2\u20AC|\uFFFD)/u.test(text);
    }

    function nanfinMojibakeScore(text) {
        if (typeof text !== "string" || text === "") {
            return 0;
        }
        var bad = (text.match(/(?:\u00C3|\u00C2|\u00E0[\u00B8\u00B9\u00BA]|\u00E2\u20AC|\uFFFD)/gu) || []).length;
        var thai = (text.match(/[\u0E00-\u0E7F]/gu) || []).length;
        return (bad * 4) - thai;
    }
var cp1252Reverse = {
        8364: 128, 8218: 130, 402: 131, 8222: 132, 8230: 133, 8224: 134, 8225: 135,
        710: 136, 8240: 137, 352: 138, 8249: 139, 338: 140, 381: 142, 8216: 145,
        8217: 146, 8220: 147, 8221: 148, 8226: 149, 8211: 150, 8212: 151, 732: 152,
        8482: 153, 353: 154, 8250: 155, 339: 156, 382: 158, 376: 159
    };

    function nanfinLatinishTextToBytes(text) {
        if (typeof text !== "string") {
            return null;
        }
        var bytes = [];
        var chars = Array.from(text);
        for (var i = 0; i < chars.length; i++) {
            var cp = chars[i].codePointAt(0);
            if (cp <= 255) {
                bytes.push(cp);
                continue;
            }
            if (Object.prototype.hasOwnProperty.call(cp1252Reverse, cp)) {
                bytes.push(cp1252Reverse[cp]);
                continue;
            }
            return null;
        }
        return new Uint8Array(bytes);
    }

    function nanfinRepairMojibakeChunk(chunk) {
        if (typeof chunk !== "string" || chunk === "" || !nanfinContainsMojibake(chunk)) {
            return chunk;
        }
        if (typeof TextDecoder !== "function") {
            return chunk;
        }

        var variants = [chunk];
        var current = chunk;
        var decoder = new TextDecoder("utf-8", { fatal: true });

        for (var i = 0; i < 3; i++) {
            var bytes = nanfinLatinishTextToBytes(current);
            if (!(bytes instanceof Uint8Array) || bytes.length === 0) {
                break;
            }

            var candidate = "";
            try {
                candidate = decoder.decode(bytes);
            } catch (err) {
                break;
            }

            if (!candidate || candidate === current) {
                break;
            }

            variants.push(candidate);
            current = candidate;
            if (!nanfinContainsMojibake(current)) {
                break;
            }
        }

        var chosen = chunk;
        var chosenScore = nanfinMojibakeScore(chunk);
        var chosenThai = (chunk.match(/[\u0E00-\u0E7F]/gu) || []).length;

        for (var j = 0; j < variants.length; j++) {
            var candidateText = variants[j];
            var candidateScore = nanfinMojibakeScore(candidateText);
            var candidateThai = (candidateText.match(/[\u0E00-\u0E7F]/gu) || []).length;
            if (candidateScore < chosenScore || (candidateScore === chosenScore && candidateThai > chosenThai)) {
                chosen = candidateText;
                chosenScore = candidateScore;
                chosenThai = candidateThai;
            }
        }

        return chosen;
    }

    function nanfinNormalizeText(text) {
        if (typeof text !== "string" || text === "" || !nanfinContainsMojibake(text)) {
            return text;
        }
        return text.replace(/[\u0080-\u24FF]{2,}/gu, function (chunk) {
            return nanfinRepairMojibakeChunk(chunk);
        });
    }

    function nanfinTranslateText(text) {
        var normalized = nanfinNormalizeText(text);
        if (nanfinUiLang !== "en" || typeof normalized !== "string" || normalized === "" || !/[\u0E00-\u0E7F]/u.test(normalized)) {
            return normalized;
        }

        var exactMap = {
            "Dashboard": "Dashboard",
            "Login": "Sign In",
            "Log out": "Sign Out",
            "Username": "Username",
            "password": "Password",
            "branch": "Branch",
            "product": "Product",
            "Start of the month": "Start Month",
            "Until the month": "End Month",
            "Filter graphs": "Filter Chart",
            "Clear value": "Clear",
            "search": "Search",
            "wash": "Clear",
            "turn off": "Close",
            "increase": "Add",
            "Add item": "Add Item",
            "Add a new item": "Add New Record",
            "correct": "Edit",
            "delete": "Delete",
            "approve": "Approve",
            "manage": "Actions",
            "Manage users": "User Management",
            "Manage branches": "Branch Management",
            "all": "Total",
            "Waiting to check": "Pending Review",
            "Approved": "Approved",
            "Logical delete": "Soft Deleted",
            "status": "Status",
            "Version": "Version",
            "Latest update": "Last Updated",
            "Latest editor": "Last Updated By",
            "Summary": "Preview",
            "management": "Actions",
            "There is no information yet.": "No data yet",
            "There are no notifications yet.": "No notifications",
            "No information found": "No data found",
            "The searched information was not found.": "No matching data found",
            "File not selected yet.": "No file selected",
            "File no more than 5 MB": "File size must not exceed 5 MB",
            "full screen": "Fullscreen",
            "short": "Exit Fullscreen",
            "System users": "System User",
            "User role": "User Role",
            "Change password": "Change Password",
            "Current password": "Current Password",
            "New password": "New Password",
            "Confirm new password": "Confirm New Password",
            "Save the new password.": "Save New Password",
            "Explanation": "Disclaimer",
            "user": "User",
            "time": "Time",
            "list": "items",
            "Case": "cases",
            "please wait a moment And don't turn off the screen.": "Please wait and do not close this page.",
            "Processing OCR documents": "Processing OCR documents",
            "The system is reading data from Account statement": "The system is reading bank statement documents.",
            "Admin menu": "Admin Menu",
            "Manage branches": "Branch Management",
            "Manage users": "User Management",
            "Add a branch": "Add Branch",
            "Show a list of branches": "Show Branch List",
            "add user": "Add User",
            "Show a list of users": "Show User List",
            "Active branch": "Active Branches",
            "All branches (latest)": "Total Branches (Latest)",
            "Active users": "Active Users",
            "All users (latest)": "Total Users (Latest)",
            "User level": "User Level",
            "Branch list": "Branch List",
            "Branch code": "Branch Code",
            "Branch name": "Branch Name",
            "region": "Region",
            "use": "Active",
            "Deleted": "Deleted",
            "Branch record": "Save Branch",
            "Save the edits.": "Save Changes",
            "User record": "Save User",
            "Item code": "Record ID",
            "Main references": "Primary Reference",
            "Main name": "Primary Name",
            "Latest results (edit/delete immediately)": "Latest Records (Edit/Delete)",
            "Debt repayment attitude": "Repayment Attitude",
            "Save the new version.": "Save New Version",
            "CancelEdit": "Cancel Edit",
            "Reason/Note Recording": "Reason / Audit Note",
            "Editing ID": "Editing ID",
            "This item can be uploaded no more than": "This item allows up to",
            "Please attach all required files.": "Please attach all required files.",
            "Please complete the required information.": "Please complete all required list entries.",
            "Mr.": "Mr.",
            "Mrs.": "Mrs.",
            "miss": "Ms.",
            "other": "Other",
            "man": "Male",
            "female": "Female",
            "own house": "Own House",
            "Relative's house": "Family House",
            "House for rent": "Rented House",
            "service": "Government",
            "private": "Private Sector",
            "Agriculture": "Agriculture",
            "free": "Freelance",
            "personal business": "Self-employed"
        };
        if (Object.prototype.hasOwnProperty.call(exactMap, normalized)) {
            return exactMap[normalized];
        }

        var translated = normalized;
        var replacePairs = [
            ["by ", "By "],
            ["sector ", "Region "],
            ["Every branch", "All Branches"],
            ["every product", "All Products"],
            ["every month", "All Month Ranges"],
            ["A system that ", "Module "],
            ["increase", "Add "],
            ["correct", "Edit "],
            ["delete", "Delete "],
            ["record", "Save "],
            ["list", "item"],
            ["Not found", "Not found"],
            ["cannot", "Unable to "],
            ["please", "Please "],
            ["tidy", "successfully"],
            ["User", "user"],
            ["branch", "branch"],
            ["Daily", "Daily"],
            ["weekly", "Weekly"],
            ["Monthly", "Monthly"],
            ["Quarterly", "Quarterly"],
            ["yearly", "Yearly"],
            ["There is no event information yet.", "No event records yet"],
            ["Latest notification", "Latest Notifications"],
            ["Latest Event Ledger", "Latest Event Ledger"]
        ];
        for (var i = 0; i < replacePairs.length; i++) {
            translated = translated.split(replacePairs[i][0]).join(replacePairs[i][1]);
        }

        translated = translated.replace(/(\d+)\s*-\s*(\d+)\s*Days/gu, "$1-$2 Days");
        translated = translated.replace(/(\d+)\+\s*Days/gu, "$1+ Days");
        translated = translated.replace(/\sto\s/gu, " to ");
        translated = translated.replace(/--\s*Select\s*--/gu, "-- Select --");
        translated = translated.replace(/\s{2,}/gu, " ").trim();

        return translated;
    }

    function nanfinEnsureUtf8Forms(root) {
        if (!root) {
            return;
        }

        var forms = [];
        if (root.nodeType === 1 && root.tagName && root.tagName.toLowerCase() === "form") {
            forms.push(root);
        }
        if (root.querySelectorAll) {
            var foundForms = root.querySelectorAll("form");
            for (var i = 0; i < foundForms.length; i++) {
                forms.push(foundForms[i]);
            }
        }

        for (var j = 0; j < forms.length; j++) {
            var form = forms[j];
            var currentCharset = (form.getAttribute("accept-charset") || "").toUpperCase();
            if (currentCharset !== "UTF-8") {
                form.setAttribute("accept-charset", "UTF-8");
            }
        }
    }

    function nanfinNormalizeDom(root) {
        if (!root || !root.ownerDocument) {
            return;
        }

        var attrNames = ["title", "placeholder", "aria-label", "data-bs-original-title", "value"];
        var startNode = root.nodeType === 1 ? root : root.parentElement;
        if (!startNode) {
            return;
        }

        nanfinEnsureUtf8Forms(startNode);

        var walker = document.createTreeWalker(startNode, NodeFilter.SHOW_TEXT, null);
        var textNode = walker.nextNode();
        while (textNode) {
            var text = textNode.nodeValue || "";
            var fixedText = nanfinNormalizeText(text);
            var localizedText = nanfinTranslateText(fixedText);
            if (localizedText !== text) {
                textNode.nodeValue = localizedText;
            }
            textNode = walker.nextNode();
        }

        var elements = startNode.querySelectorAll("*");
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            for (var a = 0; a < attrNames.length; a++) {
                var attr = attrNames[a];
                if (!el.hasAttribute(attr)) {
                    continue;
                }
                var value = el.getAttribute(attr) || "";
                var fixedValue = nanfinNormalizeText(value);
                var localizedValue = nanfinTranslateText(fixedValue);
                if (localizedValue !== value) {
                    el.setAttribute(attr, localizedValue);
                }
            }
        }

        if (startNode.nodeType === 1) {
            for (var r = 0; r < attrNames.length; r++) {
                var rootAttr = attrNames[r];
                if (!startNode.hasAttribute(rootAttr)) {
                    continue;
                }
                var rootVal = startNode.getAttribute(rootAttr) || "";
                var rootFixed = nanfinNormalizeText(rootVal);
                var rootLocalized = nanfinTranslateText(rootFixed);
                if (rootLocalized !== rootVal) {
                    startNode.setAttribute(rootAttr, rootLocalized);
                }
            }
        }
    }

    function nanfinPatchPopupFunctions() {
        if (window.__nanfinPopupPatched) {
            return;
        }
        window.__nanfinPopupPatched = true;

        var rawAlert = window.alert;
        var rawConfirm = window.confirm;
        var rawPrompt = window.prompt;

        window.alert = function (message) {
            return rawAlert.call(window, nanfinTranslateText(nanfinNormalizeText(String(message == null ? "" : message))));
        };
        window.confirm = function (message) {
            return rawConfirm.call(window, nanfinTranslateText(nanfinNormalizeText(String(message == null ? "" : message))));
        };
        window.prompt = function (message, defaultValue) {
            return rawPrompt.call(
                window,
                nanfinTranslateText(nanfinNormalizeText(String(message == null ? "" : message))),
                defaultValue
            );
        };
    }

    function nanfinInstallDomObserver() {
        if (window.__nanfinObserverInstalled || !document.documentElement) {
            return;
        }
        window.__nanfinObserverInstalled = true;

        var running = false;
        var observer = new MutationObserver(function (mutations) {
            if (running) {
                return;
            }
            running = true;
            try {
                for (var i = 0; i < mutations.length; i++) {
                    var mutation = mutations[i];
                    if (mutation.type === "characterData" && mutation.target) {
                        var oldText = mutation.target.nodeValue || "";
                        var fixedText = nanfinTranslateText(nanfinNormalizeText(oldText));
                        if (fixedText !== oldText) {
                            mutation.target.nodeValue = fixedText;
                        }
                        continue;
                    }

                    if (mutation.type === "attributes" && mutation.target && mutation.attributeName) {
                        var oldAttr = mutation.target.getAttribute(mutation.attributeName) || "";
                        var fixedAttr = nanfinTranslateText(nanfinNormalizeText(oldAttr));
                        if (fixedAttr !== oldAttr) {
                            mutation.target.setAttribute(mutation.attributeName, fixedAttr);
                        }
                        continue;
                    }

                    if (mutation.type === "childList" && mutation.addedNodes && mutation.addedNodes.length) {
                        for (var n = 0; n < mutation.addedNodes.length; n++) {
                            var added = mutation.addedNodes[n];
                            if (added.nodeType === 1) {
                                nanfinNormalizeDom(added);
                            } else if (added.nodeType === 3 && added.parentElement) {
                                nanfinNormalizeDom(added.parentElement);
                            }
                        }
                    }
                }
            } finally {
                running = false;
            }
        });

        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ["title", "placeholder", "aria-label", "data-bs-original-title", "value"]
        });
    }

    window.nanfinNormalizeText = nanfinNormalizeText;
    window.nanfinNormalizeDom = nanfinNormalizeDom;
    window.nanfinTranslateText = nanfinTranslateText;

    if (document.documentElement) {
        document.documentElement.setAttribute("lang", "en");
    }

    nanfinPatchPopupFunctions();
    if (document.body) {
        nanfinNormalizeDom(document.body);
    } else {
        document.addEventListener("DOMContentLoaded", function () {
            nanfinNormalizeDom(document.body);
        });
    }
    document.title = nanfinTranslateText(nanfinNormalizeText(String(document.title || "")));

    document.addEventListener("shown.bs.modal", function (event) {
        nanfinNormalizeDom(event.target);
    });

    nanfinInstallDomObserver();
})();

$(function () {
    var dataTableLanguage = {
        emptyTable: "No data available",
        info: "Showing _START_ to _END_ of _TOTAL_ entries",
        infoEmpty: "Showing 0 to 0 of 0 entries",
        infoFiltered: "(filtered from _MAX_ total entries)",
        zeroRecords: "No matching records found",
        paginate: {
            first: "First",
            last: "Last",
            next: "Next",
            previous: "Previous"
        }
    };

    if ($.fn.DataTable) {
        $(".js-module-datatable").each(function () {
            if ($.fn.DataTable.isDataTable(this)) {
                return;
            }

            $(this).DataTable({
                pageLength: 50,
                lengthChange: false,
                lengthMenu: [[50], [50]],
                searching: false,
                info: true,
                autoWidth: false,
                order: [],
                columnDefs: [
                    { targets: [8, 9], orderable: false }
                ],
                language: dataTableLanguage
            });
        });

        $(".js-admin-datatable").each(function () {
            if ($.fn.DataTable.isDataTable(this)) {
                return;
            }

            $(this).DataTable({
                pageLength: 50,
                lengthChange: false,
                lengthMenu: [[50], [50]],
                searching: true,
                info: true,
                autoWidth: false,
                order: [],
                columnDefs: [
                    { targets: -1, orderable: false }
                ],
                language: dataTableLanguage
            });
        });

        $(document).on("shown.bs.modal", ".modal", function () {
            $(this).find(".js-admin-datatable").each(function () {
                if ($.fn.DataTable.isDataTable(this)) {
                    $(this).DataTable().columns.adjust();
                }
            });
        });
    }

        function syncFieldValidationState(field) {
        if (!field) {
            return;
        }
        var $field = $(field);
        if (typeof field.checkValidity === "function" && !field.checkValidity()) {
            $field.addClass("is-invalid").removeClass("is-valid");
        } else {
            $field.removeClass("is-invalid");
            if ($field.val() !== "") {
                $field.addClass("is-valid");
            } else {
                $field.removeClass("is-valid");
            }
        }
    }

    function focusFirstInvalid($form) {
        var firstInvalid = $form.find(":invalid, .is-invalid").filter(":visible").first();
        if (firstInvalid.length) {
            firstInvalid.trigger("focus");
        }
    }

    $(document).on("input change blur", ".validate-form input, .validate-form select, .validate-form textarea", function () {
        syncFieldValidationState(this);
    });

    $(".validate-form").on("submit", function (event) {
        var form = this;
        var $form = $(form);
        $form.find("input, select, textarea").each(function () {
            syncFieldValidationState(this);
        });

        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            $form.addClass("was-validated");
            focusFirstInvalid($form);
        }
    });

    $(".needs-confirm-delete").on("submit", function (event) {
        if (!window.confirm("Confirm soft delete?")) {
            event.preventDefault();
        }
    });

    function parseNullableNumber(rawValue) {
        var text = String(rawValue == null ? "" : rawValue).replace(/,/g, "").trim();
        if (text === "") {
            return null;
        }
        var numberValue = Number(text);
        return Number.isFinite(numberValue) ? numberValue : null;
    }

    function setNullableNumberValue($input, value) {
        if (!$input || !$input.length) {
            return;
        }
        if (value === null || !Number.isFinite(value)) {
            $input.val("");
            return;
        }
        $input.val(value.toFixed(2));
    }

    function creditPolicyIncomeMidpoint(incomeBand) {
        var map = {
            "10000-14999": 12500,
            "15000-24999": 20000,
            "25000-39999": 32500,
            "40000-59999": 50000,
            "60000+": 65000
        };
        return Object.prototype.hasOwnProperty.call(map, incomeBand) ? map[incomeBand] : null;
    }

    function applyCreditPolicyAutoCalculation($form) {
        if (!$form || !$form.length) {
            return;
        }

        var incomeBand = String($form.find('[name="income_band_ref"]').val() || "").trim();
        var maxDsrPct = parseNullableNumber($form.find('[name="max_dsr_pct"]').val());
        var existingDebt = parseNullableNumber($form.find('[name="debt_obligation_ref"]').val());
        var collateralValue = parseNullableNumber($form.find('[name="collateral_value_ref"]').val());
        var maxLtvPct = parseNullableNumber($form.find('[name="max_ltv_pct"]').val());
        var annualRatePct = parseNullableNumber($form.find('[name="policy_interest_rate_pct"]').val());
        var tenorMonth = parseNullableNumber($form.find('[name="max_tenor_month"]').val());

        var incomeMidpoint = creditPolicyIncomeMidpoint(incomeBand);
        var debtPerMonth = existingDebt !== null ? existingDebt : 0;
        var installmentCapacity = null;
        if (incomeMidpoint !== null && maxDsrPct !== null) {
            installmentCapacity = (incomeMidpoint * (maxDsrPct / 100)) - debtPerMonth;
            if (installmentCapacity < 0) {
                installmentCapacity = 0;
            }
        }

        var loanByDsr = null;
        if (installmentCapacity !== null && tenorMonth !== null && tenorMonth > 0) {
            if (annualRatePct !== null && annualRatePct > 0) {
                var monthlyRate = annualRatePct / 1200;
                var factor = (1 - Math.pow(1 + monthlyRate, -tenorMonth)) / monthlyRate;
                if (Number.isFinite(factor) && factor > 0) {
                    loanByDsr = installmentCapacity * factor;
                }
            } else {
                loanByDsr = installmentCapacity * tenorMonth;
            }
        }

        var loanByLtv = null;
        if (collateralValue !== null && maxLtvPct !== null) {
            loanByLtv = collateralValue * (maxLtvPct / 100);
        }

        var recommendedLoan = null;
        if (loanByDsr !== null && loanByLtv !== null) {
            recommendedLoan = Math.min(loanByDsr, loanByLtv);
        } else if (loanByDsr !== null) {
            recommendedLoan = loanByDsr;
        } else if (loanByLtv !== null) {
            recommendedLoan = loanByLtv;
        }

        if (recommendedLoan !== null && recommendedLoan < 0) {
            recommendedLoan = 0;
        }

        setNullableNumberValue($form.find('[name="income_midpoint_ref"]'), incomeMidpoint);
        setNullableNumberValue($form.find('[name="recommended_installment"]'), installmentCapacity);
        setNullableNumberValue($form.find('[name="recommended_loan_amount"]'), recommendedLoan);
    }

    function bindCreditPolicyAutoCalc($root) {
        var $forms = $();
        if ($root && $root.length) {
            if ($root.is("form.validate-form")) {
                $forms = $forms.add($root);
            }
            $forms = $forms.add($root.find("form.validate-form"));
        } else {
            $forms = $("form.validate-form");
        }

        $forms.each(function () {
            var $form = $(this);
            if (!$form.find('input[name="module_key"][value="credit_policy"]').length) {
                return;
            }
            if ($form.data("creditPolicyAutoCalcBound")) {
                applyCreditPolicyAutoCalculation($form);
                return;
            }
            $form.data("creditPolicyAutoCalcBound", true);

            $.each(["income_midpoint_ref", "recommended_installment", "recommended_loan_amount"], function (_, fieldName) {
                var $field = $form.find('[name="' + fieldName + '"]');
                if ($field.length) {
                    $field.prop("readonly", true).addClass("bg-light");
                }
            });

            var watchedSelector = [
                '[name="income_band_ref"]',
                '[name="max_dsr_pct"]',
                '[name="debt_obligation_ref"]',
                '[name="collateral_value_ref"]',
                '[name="max_ltv_pct"]',
                '[name="policy_interest_rate_pct"]',
                '[name="max_tenor_month"]'
            ].join(", ");

            $form.on("input change", watchedSelector, function () {
                applyCreditPolicyAutoCalculation($form);
            });

            applyCreditPolicyAutoCalculation($form);
        });
    }

    bindCreditPolicyAutoCalc($(document));

    $(document).on("shown.bs.modal", "#entryModal", function () {
        bindCreditPolicyAutoCalc($(this));
    });

    $(document).on("click", ".js-edit-branch-btn", function () {
        var btn = $(this);
        $("#branch_edit_source_id").val(btn.data("source-id") || "");
        $("#branch_edit_code").val(btn.data("branch-code") || "");
        $("#branch_edit_name").val(btn.data("branch-name") || "");
        $("#branch_edit_region").val(btn.data("region-name") || "");
    });

    $(document).on("click", ".js-edit-user-btn", function () {
        var btn = $(this);
        $("#user_edit_source_id").val(btn.data("source-id") || "");
        $("#user_edit_user_name").val(btn.data("user-name") || "");
        $("#user_edit_display_name").val(btn.data("display-name") || "");
        $("#user_edit_role_name").val(btn.data("role-name") || "");
        $("#user_edit_branch_code").val(btn.data("branch-code") || "");
    });

    $(document).on("click", ".js-edit-occupation-btn", function () {
        var btn = $(this);
        $("#occupation_edit_source_id").val(btn.data("source-id") || "");
        $("#occupation_edit_code").val(btn.data("occupation-code") || "");
        $("#occupation_edit_name").val(btn.data("occupation-name") || "");
        $("#occupation_edit_type").val(btn.data("employment-type") || "");
        $("#occupation_edit_province").val(btn.data("province-name") || "");
        $("#occupation_edit_income_min").val(btn.data("avg-income-min") || "");
        $("#occupation_edit_income_default").val(btn.data("avg-income-default") || "");
        $("#occupation_edit_income_max").val(btn.data("avg-income-max") || "");
        $("#occupation_edit_agri_detail").val(btn.data("agri-detail") || "");
        $("#occupation_edit_note").val(btn.data("note-text") || "");
    });

    $(document).on("click", ".js-edit-car-master-btn", function () {
        var btn = $(this);
        $("#car_master_edit_source_id").val(btn.data("source-id") || "");
        $("#car_master_edit_brand_name").val(btn.data("brand-name") || "");
        $("#car_master_edit_model_name").val(btn.data("model-name") || "");
        $("#car_master_edit_note").val(btn.data("note-text") || "");
    });

    var shouldOpenEntryModal = !!window.smartFinanceOpenEntryModal;
    if (window.bootstrap && shouldOpenEntryModal) {
        var entryModalEl = document.getElementById("entryModal");
        if (entryModalEl) {
            var entryModal = window.bootstrap.Modal.getOrCreateInstance(entryModalEl);
            entryModal.show();
        }
    }

    function attachResizableClassToModal(modalElement) {
        if (!modalElement) {
            return;
        }
        var $dialog = $(modalElement).find(".modal-dialog").first();
        if (!$dialog.length) {
            return;
        }
        $dialog.addClass("sf-resizable-modal");
    }

    function updateFullscreenToggleButton($button, isFullscreen) {
        if (!$button || !$button.length) {
            return;
        }
        var expandLabel = String($button.data("expand-label") || "Fullscreen");
        var collapseLabel = String($button.data("collapse-label") || "Exit Fullscreen");
        $button.attr("aria-pressed", isFullscreen ? "true" : "false");
        $button.text(isFullscreen ? collapseLabel : expandLabel);
    }

    $(document).on("click", ".js-toggle-modal-fullscreen", function () {
        var $button = $(this);
        var modalSelector = String($button.data("modal-target") || "").trim();
        var $modal = modalSelector ? $(modalSelector).first() : $button.closest(".modal");
        if (!$modal.length) {
            return;
        }

        var $dialog = $modal.find(".modal-dialog").first();
        if (!$dialog.length) {
            return;
        }

        var nextState = !$dialog.hasClass("modal-fullscreen");
        $dialog.toggleClass("modal-fullscreen", nextState);
        if (nextState) {
            $dialog.removeClass("sf-resizable-modal");
        } else {
            $dialog.addClass("sf-resizable-modal");
        }
        updateFullscreenToggleButton($button, nextState);
    });

    $(".modal").each(function () {
        attachResizableClassToModal(this);
    });

    $(document).on("shown.bs.modal", ".modal", function () {
        attachResizableClassToModal(this);
        var $modal = $(this);
        var $dialog = $modal.find(".modal-dialog").first();
        updateFullscreenToggleButton(
            $modal.find(".js-toggle-modal-fullscreen").first(),
            $dialog.hasClass("modal-fullscreen")
        );
    });

    $(document).on("hidden.bs.modal", ".modal", function () {
        var $modal = $(this);
        var $dialog = $modal.find(".modal-dialog").first();
        if ($dialog.hasClass("modal-fullscreen")) {
            $dialog.removeClass("modal-fullscreen").addClass("sf-resizable-modal");
        }
        updateFullscreenToggleButton($modal.find(".js-toggle-modal-fullscreen").first(), false);
    });

    $(document).on("click", ".js-scroll-to-attitude", function () {
        var $btn = $(this);
        var targetSelector = String($btn.data("target") || "").trim();
        if (!targetSelector) {
            return;
        }

        var $modalBody = $btn.closest(".modal-content").find(".modal-body").first();
        if (!$modalBody.length) {
            return;
        }

        var $target = $modalBody.find(targetSelector).first();
        if (!$target.length) {
            return;
        }

        var scrollTop = $modalBody.scrollTop() + $target.position().top - 8;
        $modalBody.animate({ scrollTop: Math.max(0, scrollTop) }, 250);
    });

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function safeParseJsonArray(raw) {
        if (typeof raw !== "string" || raw.trim() === "") {
            return [];
        }

        try {
            var decoded = JSON.parse(raw);
            return Array.isArray(decoded) ? decoded : [];
        } catch (err) {
            return [];
        }
    }

    var jsonListModalEl = document.getElementById("jsonListItemModal");
    var jsonListModal = jsonListModalEl && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(jsonListModalEl) : null;
    var jsonListState = {
        fieldName: "",
        rowIndex: -1
    };
    var jsonListRegistry = {};

    function normalizeColumns(rawColumns) {
        if (!Array.isArray(rawColumns)) {
            return [];
        }

        return $.map(rawColumns, function (column) {
            if (!column || typeof column !== "object") {
                return null;
            }
            var key = String(column.key || "").trim();
            if (!key) {
                return null;
            }
            return {
                key: key,
                label: String(column.label || key),
                input: String(column.input || "text").toLowerCase(),
                required: !!column.required,
                accept: String(column.accept || "").trim(),
                dependsOn: String(column.depends_on || "").trim(),
                options: Array.isArray(column.options) ? column.options : [],
                optionsMap: column.options_map && typeof column.options_map === "object" ? column.options_map : {}
            };
        });
    }

    function normalizeOptionList(rawOptions) {
        if (!Array.isArray(rawOptions)) {
            return [];
        }
        return $.map(rawOptions, function (option) {
            var value = String(option || "").trim();
            return value ? value : null;
        });
    }

    function resolveColumnOptions(column, rowData) {
        var options = normalizeOptionList(column.options || []);
        if (column.dependsOn) {
            var parentValue = String((rowData && rowData[column.dependsOn]) || "").trim();
            var optionsMap = column.optionsMap && typeof column.optionsMap === "object" ? column.optionsMap : {};
            var mapped = normalizeOptionList(optionsMap[parentValue] || []);
            if (mapped.length) {
                options = mapped;
            } else if (parentValue) {
                options = [];
            }
        }
        return options;
    }

    function collectJsonListEditorValues($form) {
        var rowData = {};
        $form.find(".js-json-list-editor-input").each(function () {
            var $input = $(this);
            var key = String($input.data("key") || "");
            if (!key) {
                return;
            }
            rowData[key] = String($input.val() || "").trim();
        });
        return rowData;
    }

    function refreshJsonListDependentSelects(ctx, changedKey, $form) {
        if (!ctx) {
            return;
        }
        var currentRow = collectJsonListEditorValues($form);
        $.each(ctx.columns, function (_, column) {
            if (column.input !== "select" || !column.dependsOn || column.dependsOn !== changedKey) {
                return;
            }
            var $target = $form.find('.js-json-list-editor-input[data-key="' + column.key + '"]');
            if (!$target.length) {
                return;
            }
            var previousValue = String($target.val() || "").trim();
            var options = resolveColumnOptions(column, currentRow);
            var html = '<option value="">-- Select --</option>';
            $.each(options, function (_, optionValue) {
                var selected = optionValue === previousValue ? " selected" : "";
                html += '<option value="' + escapeHtml(optionValue) + '"' + selected + ">" + escapeHtml(optionValue) + "</option>";
            });
            $target.html(html);
            if (options.indexOf(previousValue) === -1) {
                $target.val("");
            }
        });
    }

    function registerJsonListField($field) {
        var fieldName = String($field.data("field-name") || "").trim();
        if (!fieldName) {
            return;
        }

        var columns = normalizeColumns(safeParseJsonArray(String($field.attr("data-columns") || "[]")));
        var $input = $field.find(".js-json-list-input");
        var rows = safeParseJsonArray(String($input.val() || "[]"));

        jsonListRegistry[fieldName] = {
            fieldName: fieldName,
            label: String($field.data("field-label") || fieldName),
            required: String($field.data("required") || "0") === "1",
            maxItems: parseInt(String($field.data("max-items") || "0"), 10) || 0,
            columns: columns,
            rows: rows,
            $field: $field,
            $input: $input
        };
    }

    function summarizeFileValue(rawValue) {
        var value = String(rawValue || "").trim();
        if (!value) {
            return "";
        }
        if (value.indexOf("data:") === 0) {
            return "New Attachment";
        }
        var slashPos = value.lastIndexOf("/");
        if (slashPos >= 0 && slashPos < value.length - 1) {
            return value.substring(slashPos + 1);
        }
        return value;
    }

    function renderJsonListField(fieldName) {
        var ctx = jsonListRegistry[fieldName];
        if (!ctx) {
            return;
        }

        ctx.$input.val(JSON.stringify(ctx.rows));
        var $body = ctx.$field.find(".js-json-list-body");
        var countText = ctx.rows.length + " items";
        ctx.$field.find(".js-json-list-count").text(countText);
        var reachedMax = ctx.maxItems > 0 && ctx.rows.length >= ctx.maxItems;
        ctx.$field.find(".js-json-list-add").prop("disabled", reachedMax);

        if (!ctx.rows.length) {
            $body.html('<tr><td class="text-muted text-center" colspan="' + (ctx.columns.length + 1) + '">No data available</td></tr>');
            return;
        }

        var html = "";
        $.each(ctx.rows, function (index, row) {
            html += "<tr>";
            $.each(ctx.columns, function (_, column) {
                var value = row && row[column.key] !== undefined && row[column.key] !== null ? row[column.key] : "";
                if (column.input === "file") {
                    var fileSummary = summarizeFileValue(value);
                    if (String(value || "").indexOf("data:") === 0) {
                        html += "<td>" + escapeHtml(fileSummary) + "</td>";
                    } else if (String(value || "").trim() !== "") {
                        html += '<td><a href="' + escapeHtml(value) + '" target="_blank" rel="noopener">' + escapeHtml(fileSummary) + "</a></td>";
                    } else {
                        html += "<td>-</td>";
                    }
                    return;
                }
                html += "<td>" + (value === "" ? "-" : escapeHtml(value)) + "</td>";
            });
            html += '<td class="text-end">';
            html += '<button type="button" class="btn btn-sm btn-outline-primary me-1 js-json-list-edit" data-field-name="' + escapeHtml(fieldName) + '" data-row-index="' + index + '">Edit</button>';
            html += '<button type="button" class="btn btn-sm btn-outline-danger js-json-list-delete" data-field-name="' + escapeHtml(fieldName) + '" data-row-index="' + index + '">Delete</button>';
            html += "</td>";
            html += "</tr>";
        });

        $body.html(html);
    }

    function openJsonListEditor(fieldName, rowIndex) {
        var ctx = jsonListRegistry[fieldName];
        if (!ctx || !jsonListModal) {
            return;
        }

        jsonListState.fieldName = fieldName;
        jsonListState.rowIndex = rowIndex;

        var row = rowIndex >= 0 && ctx.rows[rowIndex] ? ctx.rows[rowIndex] : {};
        var title = (rowIndex >= 0 ? "Edit Item" : "Add Item") + " - " + ctx.label;
        $("#jsonListItemModalLabel").text(title);

        var fieldsHtml = "";
        $.each(ctx.columns, function (_, column) {
            var key = column.key;
            var value = row[key] !== undefined && row[key] !== null ? String(row[key]) : "";
            var requiredAttr = column.required ? " required" : "";

            fieldsHtml += '<div class="mb-3">';
            fieldsHtml += '<label class="form-label">' + escapeHtml(column.label) + (column.required ? " *" : "") + "</label>";

            if (column.input === "select") {
                var selectOptions = resolveColumnOptions(column, row);
                if (value && $.inArray(value, selectOptions) === -1) {
                    selectOptions.unshift(value);
                }
                fieldsHtml += '<select class="form-select js-json-list-editor-input" data-key="' + escapeHtml(key) + '"' + requiredAttr + ">";
                fieldsHtml += '<option value="">-- Select --</option>';
                $.each(selectOptions, function (_, option) {
                    var optionValue = String(option || "");
                    var selected = optionValue === value ? " selected" : "";
                    fieldsHtml += '<option value="' + escapeHtml(optionValue) + '"' + selected + ">" + escapeHtml(optionValue) + "</option>";
                });
                fieldsHtml += "</select>";
            } else if (column.input === "date") {
                fieldsHtml += '<input type="date" class="form-control js-json-list-editor-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '"' + requiredAttr + ">";
            } else if (column.input === "number") {
                fieldsHtml += '<input type="number" step="0.01" class="form-control js-json-list-editor-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '"' + requiredAttr + ">";
            } else if (column.input === "file") {
                var accept = String(column.accept || "").trim();
                var acceptAttr = accept ? ' accept="' + escapeHtml(accept) + '"' : "";
                var fileSummary = summarizeFileValue(value);
                fieldsHtml += '<input type="text" class="form-control mb-2 js-json-list-editor-file-display" data-file-key="' + escapeHtml(key) + '" value="' + escapeHtml(fileSummary) + '"' + requiredAttr + ' readonly placeholder="No file selected">';
                fieldsHtml += '<input type="hidden" class="js-json-list-editor-input js-json-list-editor-file-value" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '">';
                fieldsHtml += '<input type="file" class="form-control js-json-list-editor-file-picker" data-file-key="' + escapeHtml(key) + '"' + acceptAttr + ">";
                fieldsHtml += '<small class="text-muted d-block mt-1">File size must not exceed 5 MB</small>';
            } else {
                fieldsHtml += '<input type="text" class="form-control js-json-list-editor-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '"' + requiredAttr + ">";
            }

            fieldsHtml += "</div>";
        });

        $("#jsonListItemFields").html(fieldsHtml);
        $("#jsonListItemForm").removeClass("was-validated");
        jsonListModal.show();
    }

    if (jsonListModal) {
        $(".js-json-list-field").each(function () {
            registerJsonListField($(this));
        });

        $.each(jsonListRegistry, function (fieldName) {
            renderJsonListField(fieldName);
        });

        $(document).on("click", ".js-json-list-add", function () {
            var fieldName = String($(this).closest(".js-json-list-field").data("field-name") || "");
            var ctx = jsonListRegistry[fieldName];
            if (ctx && ctx.maxItems > 0 && ctx.rows.length >= ctx.maxItems) {
                window.alert("This item supports up to " + ctx.maxItems + " files.");
                return;
            }
            openJsonListEditor(fieldName, -1);
        });

        $(document).on("click", ".js-json-list-edit", function () {
            var fieldName = String($(this).data("field-name") || "");
            var rowIndex = parseInt($(this).data("row-index"), 10);
            if (isNaN(rowIndex)) {
                return;
            }
            openJsonListEditor(fieldName, rowIndex);
        });

        $(document).on("change", "#jsonListItemForm .js-json-list-editor-input", function () {
            var fieldName = jsonListState.fieldName;
            var ctx = jsonListRegistry[fieldName];
            if (!ctx) {
                return;
            }

            var changedKey = String($(this).data("key") || "");
            if (!changedKey) {
                return;
            }
            refreshJsonListDependentSelects(ctx, changedKey, $("#jsonListItemForm"));
        });

        $(document).on("change", "#jsonListItemForm .js-json-list-editor-file-picker", function () {
            var input = this;
            var key = String($(input).data("file-key") || "");
            if (!key) {
                return;
            }

            var $form = $("#jsonListItemForm");
            var $hidden = $form.find('.js-json-list-editor-file-value[data-key="' + key + '"]');
            var $display = $form.find('.js-json-list-editor-file-display[data-file-key="' + key + '"]');
            if (!$hidden.length || !$display.length) {
                return;
            }

            if (!input.files || !input.files.length) {
                return;
            }

            var file = input.files[0];
            var maxSize = 5 * 1024 * 1024;
            if (file.size > maxSize) {
                window.alert("File size must not exceed 5 MB.");
                input.value = "";
                return;
            }

            var reader = new FileReader();
            reader.onload = function (event) {
                var dataUrl = event && event.target ? String(event.target.result || "") : "";
                if (!dataUrl) {
                    return;
                }
                $hidden.val(dataUrl);
                $display.val(String(file.name || ""));
            };
            reader.readAsDataURL(file);
        });

        $(document).on("click", ".js-json-list-delete", function () {
            var fieldName = String($(this).data("field-name") || "");
            var rowIndex = parseInt($(this).data("row-index"), 10);
            var ctx = jsonListRegistry[fieldName];
            if (!ctx || isNaN(rowIndex) || rowIndex < 0 || rowIndex >= ctx.rows.length) {
                return;
            }

            if (!window.confirm("Confirm delete this item?")) {
                return;
            }

            ctx.rows.splice(rowIndex, 1);
            renderJsonListField(fieldName);
        });

        $("#jsonListItemForm").on("submit", function (event) {
            event.preventDefault();
            event.stopPropagation();

            var form = this;
            var fieldName = jsonListState.fieldName;
            var rowIndex = jsonListState.rowIndex;
            var ctx = jsonListRegistry[fieldName];
            if (!ctx) {
                return;
            }

            if (!form.checkValidity()) {
                var $itemForm = $(form);
                $itemForm.addClass("was-validated");
                $itemForm.find("input, select, textarea").each(function () {
                    syncFieldValidationState(this);
                });
                focusFirstInvalid($itemForm);
                return;
            }

            var rowData = collectJsonListEditorValues($(form));
            var missingRequiredFile = false;
            $.each(ctx.columns, function (_, column) {
                if (column.input !== "file" || !column.required) {
                    return;
                }
                var key = String(column.key || "");
                if (!key) {
                    return;
                }
                if (!String(rowData[key] || "").trim()) {
                    missingRequiredFile = true;
                }
            });
            if (missingRequiredFile) {
                $(form).addClass("was-validated");
                window.alert("Please attach all required files.");
                return;
            }
            if (rowIndex >= 0 && rowIndex < ctx.rows.length) {
                ctx.rows[rowIndex] = rowData;
            } else {
                if (ctx.maxItems > 0 && ctx.rows.length >= ctx.maxItems) {
                    window.alert("This item supports up to " + ctx.maxItems + " files.");
                    return;
                }
                ctx.rows.push(rowData);
            }

            renderJsonListField(fieldName);
            jsonListModal.hide();
        });

        $(jsonListModalEl).on("hidden.bs.modal", function () {
            jsonListState.fieldName = "";
            jsonListState.rowIndex = -1;
            if ($(".modal.show").length) {
                $("body").addClass("modal-open");
            }
        });

        $(".validate-form").on("submit", function (event) {
            var hasListError = false;
            var $form = $(this);

            $form.find(".js-json-list-field").each(function () {
                var $field = $(this);
                var fieldName = String($field.data("field-name") || "");
                var ctx = jsonListRegistry[fieldName];
                if (!ctx) {
                    return;
                }

                var isValid = !ctx.required || ctx.rows.length > 0;
                $field.toggleClass("border border-danger rounded p-2", !isValid);
                if (!isValid) {
                    hasListError = true;
                }
            });

            if (hasListError) {
                event.preventDefault();
                event.stopPropagation();
                $form.addClass("was-validated");
                focusFirstInvalid($form);
                window.alert("Please complete all required list entries.");
            }
        });
    }

    var riskTrendData = window.smartFinanceRiskTrend;
    if (window.Chart && riskTrendData) {
        var canvas = document.getElementById("riskTrendChart");
        if (canvas) {
            new window.Chart(canvas, {
                type: "line",
                data: {
                    labels: riskTrendData.labels,
                    datasets: $.map(riskTrendData.datasets, function (dataset) {
                        return $.extend({}, dataset, {
                            label: nanfinTranslateText(String(dataset.label || "")),
                            tension: 0.28,
                            borderWidth: 3,
                            pointRadius: 2,
                            pointHoverRadius: 4,
                            fill: false
                        });
                    })
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: "index",
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: "top"
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return nanfinTranslateText(String(context.dataset.label || "")) + ": " + context.formattedValue + " cases";
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            title: {
                                display: true,
                                text: "Cases"
                            }
                        }
                    }
                }
            });
        }
    }
});

(function ($) {
    if (!$) {
        return;
    }

    var overlayEl = document.getElementById("ocrProgressOverlay");
    var progressBarEl = document.getElementById("ocrProgressBar");
    var progressHintEl = document.getElementById("ocrProgressHint");
    var progressTimer = null;
    var progressValue = 0;
    var running = false;

    function hasRequiredDom() {
        return !!(overlayEl && progressBarEl);
    }

    function updateProgress(value) {
        if (!hasRequiredDom()) {
            return;
        }
        var bounded = Math.max(0, Math.min(100, Math.round(value)));
        progressBarEl.style.width = bounded + "%";
        progressBarEl.setAttribute("aria-valuenow", String(bounded));
        progressBarEl.textContent = bounded + "%";
    }

    function stopProgress(finalValue) {
        if (progressTimer) {
            window.clearInterval(progressTimer);
            progressTimer = null;
        }
        if (typeof finalValue === "number") {
            updateProgress(finalValue);
        }
        running = false;
    }

    function hideOverlay() {
        if (!hasRequiredDom()) {
            return;
        }
        stopProgress(0);
        overlayEl.classList.remove("show");
        overlayEl.setAttribute("aria-hidden", "true");
        $("form[data-ocr-progress-lock='1']").each(function () {
            var $form = $(this);
            $form.removeAttr("data-ocr-progress-lock");
            $form.find("button[type='submit'], input[type='submit']").prop("disabled", false);
        });
        if (progressHintEl) {
            progressHintEl.innerHTML = "Please wait and do not close this page.";
        }
    }

    function startProgress(fileCount) {
        if (!hasRequiredDom()) {
            return;
        }
        stopProgress(0);
        running = true;
        overlayEl.classList.add("show");
        overlayEl.setAttribute("aria-hidden", "false");
        progressValue = 6;
        updateProgress(progressValue);
        if (progressHintEl) {
            progressHintEl.innerHTML = "Scanning " + String(fileCount) + " files • OCR may take longer for multi-page files.";
        }
        progressTimer = window.setInterval(function () {
            if (!running) {
                return;
            }
            if (progressValue < 55) {
                progressValue += 5;
            } else if (progressValue < 82) {
                progressValue += 2;
            } else if (progressValue < 94) {
                progressValue += 1;
            }
            updateProgress(progressValue);
        }, 520);
    }

    function parseStatementCount(rawValue) {
        if (!rawValue || typeof rawValue !== "string") {
            return 0;
        }
        var items;
        try {
            items = JSON.parse(rawValue);
        } catch (error) {
            return 0;
        }
        if (!Array.isArray(items)) {
            return 0;
        }
        var count = 0;
        for (var i = 0; i < items.length; i++) {
            var row = items[i];
            if (!row || typeof row !== "object") {
                continue;
            }
            var fileValue = String(row.file || row.url || row.path || "").trim();
            if (fileValue !== "") {
                count += 1;
            }
        }
        return count;
    }

    $(document).on("submit", "form.validate-form", function (event) {
        var form = this;
        var $form = $(form);

        if (event.isDefaultPrevented()) {
            return;
        }
        if ((String($form.find("input[name='module_key']").val() || "") !== "customer_360")) {
            return;
        }
        if (typeof form.checkValidity === "function" && !form.checkValidity()) {
            return;
        }
        if ($form.attr("data-ocr-progress-lock") === "1") {
            event.preventDefault();
            return;
        }

        var statementRaw = String($form.find("input[name='bank_statement_files']").val() || "");
        var statementCount = parseStatementCount(statementRaw);
        if (statementCount <= 0) {
            return;
        }

        $form.attr("data-ocr-progress-lock", "1");
        $form.find("button[type='submit'], input[type='submit']").prop("disabled", true);
        startProgress(statementCount);
    });

    window.addEventListener("pageshow", function () {
        hideOverlay();
    });

    window.addEventListener("beforeunload", function () {
        if (running) {
            stopProgress(98);
        }
    });
})(window.jQuery);
