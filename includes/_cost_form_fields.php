<?php
/* فیلدهای مشترک فرم ثبت/ویرایش هزینه — داخل پاپ‌آپ‌های costs.php استفاده می‌شود */
?>
<div>
    <label class="form-label">عنوان هزینه *</label>
    <input type="text" name="title" required class="form-input" placeholder="مثال: تعمیر آسانسور">
</div>
<div>
    <label class="form-label">مبلغ (تومان) *</label>
    <input type="number" name="amount" required min="1" step="1000" inputmode="numeric" class="form-input" placeholder="مثال: 500000">
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">نوع</label>
        <select name="cost_type" class="form-input">
            <option value="periodic">دوره‌ای</option>
            <option value="one_time">یک‌باره</option>
        </select>
    </div>
    <div>
        <label class="form-label">روش تقسیم</label>
        <select name="division_method" class="form-input">
            <option value="fixed_share">سهم ثابت</option>
            <option value="area">بر اساس متراژ</option>
            <option value="people_count">بر اساس نفر</option>
        </select>
    </div>
</div>
<div>
    <label class="form-label">مخاطب</label>
    <select name="target_audience" class="form-input">
        <option value="all">همه ساکنین</option>
        <option value="owners">فقط مالکین</option>
        <option value="tenants">فقط مستأجرین</option>
    </select>
</div>
<div>
    <label class="form-label">مهلت پرداخت (اختیاری)</label>
    <input type="date" name="due_date" class="form-input">
</div>
<div>
    <label class="form-label">توضیحات (اختیاری)</label>
    <textarea name="description" rows="2" class="form-input" placeholder="توضیح هزینه"></textarea>
</div>
