<?php /* فیلدهای مشترک فرم سند — پاپ‌آپ‌های documents.php */ ?>
<div>
    <label class="form-label">عنوان سند *</label>
    <input type="text" name="title" required class="form-input" placeholder="مثال: اساسنامه ساختمان">
</div>
<div>
    <label class="form-label">نوع سند (اختیاری)</label>
    <input type="text" name="document_type" class="form-input" placeholder="مثال: قرارداد، صورت‌جلسه، بیمه">
</div>
<div>
    <label class="form-label">آدرس فایل (لینک) *</label>
    <input type="url" name="file_path" dir="ltr" required class="form-input" style="text-align:left;" placeholder="https://example.com/file.pdf">
</div>
