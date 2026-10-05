<?php
/* فیلدهای مشترک فرم سند — پاپ‌آپ‌های documents.php
 * متغیر $document_form_mode: 'add' یا 'edit'
 */
$document_form_mode = $document_form_mode ?? 'add';
?>
<div>
    <label class="form-label">عنوان سند *</label>
    <input type="text" name="title" required class="form-input" placeholder="مثال: اساسنامه ساختمان">
</div>

<div>
    <label class="form-label">دسته‌بندی سند</label>
    <select name="document_type" class="form-input">
        <option value="legal">🏛️ اسناد مالکیت و حقوقی</option>
        <option value="financial">💰 مالی و شارژ</option>
        <option value="meeting">📝 صورت‌جلسات</option>
        <option value="contract">🤝 قراردادها</option>
        <option value="insurance">🛡️ بیمه</option>
        <option value="technical">🔧 تأسیسات و فنی</option>
        <option value="rules">📜 اساسنامه و قوانین</option>
        <option value="other">📁 سایر</option>
    </select>
</div>

<?php if ($document_form_mode === 'add'): ?>
    <div>
        <label class="form-label">نوع سند</label>
        <div class="doc-tabs">
            <label class="doc-tab">
                <input type="radio" name="source" value="file" checked>
                <span>📎 آپلود فایل</span>
            </label>
            <label class="doc-tab">
                <input type="radio" name="source" value="link">
                <span>🔗 لینک خارجی</span>
            </label>
        </div>
    </div>

    <div data-doc-source="file">
        <label class="form-label">انتخاب فایل *</label>
        <label class="doc-file-drop">
            <input type="file" name="file" required
                   accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.xls,.xlsx">
            <span class="doc-file-drop-icon">📤</span>
            <span class="doc-file-drop-text">برای انتخاب فایل اینجا لمس کنید</span>
            <span class="doc-file-drop-hint">PDF، تصویر، Word یا Excel — حداکثر ۱۰ مگابایت</span>
        </label>
    </div>

    <div data-doc-source="link" style="display:none;">
        <label class="form-label">آدرس فایل (لینک)</label>
        <input type="url" name="file_path" dir="ltr" class="form-input" style="text-align:left;" placeholder="https://example.com/file.pdf">
    </div>
<?php else: ?>
    <div data-doc-edit-file>
        <label class="form-label">تعویض فایل (اختیاری)</label>
        <label class="doc-file-drop doc-file-drop-compact">
            <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.xls,.xlsx">
            <span class="doc-file-drop-icon">🔄</span>
            <span class="doc-file-drop-text">برای جایگزینی، فایل جدید را انتخاب کنید</span>
            <span class="doc-file-drop-hint">اگر فایلی انتخاب نشود، فایل فعلی حفظ می‌شود</span>
        </label>
    </div>
<?php endif; ?>

<div>
    <label class="doc-vis-toggle">
        <input type="checkbox" name="is_visible_to_members" value="1" checked>
        <span class="doc-vis-switch" aria-hidden="true"></span>
        <span>
            <strong>قابل رویت برای اعضای ساختمان</strong>
            <small>اگر غیرفعال باشد فقط مدیران سند را می‌بینند.</small>
        </span>
    </label>
</div>

<script>
    (function () {
        /* تعویض بخش «فایل / لینک» در فرم افزودن سند */
        var scope = document.currentScript.parentElement;
        var radios = scope.querySelectorAll('input[name="source"]');
        function sync() {
            var mode = 'file';
            radios.forEach(function (r) { if (r.checked) { mode = r.value; } });
            scope.querySelectorAll('[data-doc-source]').forEach(function (box) {
                box.style.display = box.getAttribute('data-doc-source') === mode ? '' : 'none';
                /* فیلد فایل فقط وقتی بخشش فعال است الزامی باشد */
                var fileInput = box.querySelector('input[type="file"]');
                if (fileInput) {
                    fileInput.required = box.getAttribute('data-doc-source') === mode && mode === 'file';
                }
            });
        }
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();
</script>
