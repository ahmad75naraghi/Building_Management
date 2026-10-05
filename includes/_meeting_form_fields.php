<?php /* فیلدهای مشترک جلسه — پاپ‌آپ‌های meetings.php */ ?>
<div>
    <label class="form-label">عنوان جلسه *</label>
    <input type="text" name="title" required class="form-input" placeholder="مثال: مجمع عمومی سالانه">
</div>
<div>
    <label class="form-label">توضیحات / دستور جلسه</label>
    <textarea name="description" rows="3" class="form-input" placeholder="موضوعات مطرح‌شده در جلسه..."></textarea>
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">تاریخ جلسه</label>
        <input type="date" name="meeting_date" data-jalali-date class="form-input">
    </div>
    <div>
        <label class="form-label">محل برگزاری</label>
        <input type="text" name="location" class="form-input" placeholder="مثال: لابی">
    </div>
</div>
