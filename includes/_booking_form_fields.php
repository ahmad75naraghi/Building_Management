<?php /* فیلدهای مشترک رزرو — پاپ‌آپ‌های bookings.php ($common_areas) */ ?>
<div>
    <label class="form-label">مشاع *</label>
    <select name="common_area_id" required class="form-input">
        <option value="">— انتخاب مشاع —</option>
        <?php foreach ($common_areas as $area): ?>
            <option value="<?= (int) $area['id'] ?>"><?= htmlspecialchars($area['name'] ?? '') ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div>
    <label class="form-label">تاریخ رزرو *</label>
    <input type="date" name="booking_date" data-jalali-date required class="form-input">
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">از ساعت</label>
        <input type="time" name="start_time" class="form-input">
    </div>
    <div>
        <label class="form-label">تا ساعت</label>
        <input type="time" name="end_time" class="form-input">
    </div>
</div>
