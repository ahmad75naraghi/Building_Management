<?php /* فیلدهای مشترک فرم مهمان — پاپ‌آپ‌های visitors.php */ ?>
<div>
    <label class="form-label">نام مهمان *</label>
    <input type="text" name="visitor_name" required class="form-input" placeholder="مثال: علی رضایی">
</div>
<div>
    <label class="form-label">پلاک خودرو (اختیاری)</label>
    <input type="text" name="visitor_car_plate" dir="ltr" class="form-input" style="text-align:left;" placeholder="12 ب 345 ایران 11">
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">تاریخ مراجعه</label>
        <input type="date" name="visit_date" data-jalali-date class="form-input">
    </div>
    <div>
        <label class="form-label">ساعت ورود</label>
        <input type="time" name="entry_time" class="form-input">
    </div>
</div>
