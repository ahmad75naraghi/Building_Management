<?php /* فیلدهای مشترک قرائت کنتور — پاپ‌آپ‌های consumption.php ($selectable_units, $unit_labels, $is_manager) */ ?>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">نوع مصرف</label>
        <select name="consumption_type" class="form-input">
            <option value="water">آب</option>
            <option value="electricity">برق</option>
            <option value="gas">گاز</option>
        </select>
    </div>
    <div>
        <label class="form-label">واحد</label>
        <select name="unit_id" class="form-input">
            <?php if ($is_manager): ?>
                <option value="0">مشاعات (بدون واحد)</option>
            <?php endif; ?>
            <?php foreach ($selectable_units as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($unit_labels[$u['id']] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div>
    <label class="form-label">مقدار قرائت *</label>
    <input type="number" name="reading_value" required min="0" step="0.1" inputmode="decimal" class="form-input" placeholder="مثال: 150">
</div>
<div>
    <label class="form-label">تاریخ قرائت</label>
    <input type="date" name="reading_date" class="form-input">
</div>
<div>
    <label class="form-label">یادداشت (اختیاری)</label>
    <textarea name="notes" rows="2" class="form-input" placeholder="توضیح اضافه..."></textarea>
</div>
