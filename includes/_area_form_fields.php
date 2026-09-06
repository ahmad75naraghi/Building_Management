<?php /* فیلدهای مشترک مشاع — پاپ‌آپ‌های common_areas.php */ ?>
<div>
    <label class="form-label">نام مشاع *</label>
    <input type="text" name="name" required class="form-input" placeholder="مثال: سالن اجتماعات">
</div>
<div>
    <label class="form-label">نوع (اختیاری)</label>
    <input type="text" name="type" class="form-input" placeholder="مثال: سالن، استخر، پارکینگ">
</div>
<div>
    <label class="form-label">توضیحات (اختیاری)</label>
    <textarea name="description" rows="2" class="form-input" placeholder="توضیح کوتاه درباره مشاع"></textarea>
</div>
<label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
    <input type="checkbox" name="bookable" value="1" class="rounded">
    <span class="text-sm font-medium text-gray-700">قابل رزرو توسط ساکنین</span>
</label>
