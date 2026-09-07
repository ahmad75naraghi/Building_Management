<?php
/* فیلدهای مشترک فرم ثبت/ویرایش هزینه — داخل پاپ‌آپ‌های costs.php استفاده می‌شود */
?>
<div>
    <label class="form-label">عنوان هزینه *</label>
    <input type="text" name="title" required class="form-input" placeholder="مثال: رنگ‌آمیزی راه‌پله‌ها">
</div>
<div>
    <label class="form-label">مبلغ کل (تومان) *</label>
    <input type="number" name="amount" required min="1" step="1000" inputmode="numeric" class="form-input" placeholder="مثال: 500000">
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">نوع</label>
        <select name="cost_type" class="form-input">
            <option value="periodic">دوره‌ای</option>
            <option value="one_time" selected>یک‌باره</option>
        </select>
    </div>
    <div>
        <label class="form-label">روش تقسیم</label>
        <select name="division_method" class="form-input">
            <option value="fixed_share">سهم مساوی</option>
            <option value="area">بر اساس متراژ</option>
            <option value="people_count">بر اساس نفر</option>
        </select>
    </div>
</div>

<div>
    <label class="form-label">این هزینه از چه کسانی دریافت می‌شود؟ *</label>
    <div class="choice-list">
        <label class="choice-item">
            <input type="radio" name="target_audience" value="all" checked data-cost-audience>
            <span>
                <strong>همه اعضا</strong>
                <small>مالکین و مستأجرین واحدها به نسبت روش تقسیم.</small>
            </span>
        </label>
        <label class="choice-item">
            <input type="radio" name="target_audience" value="residents" data-cost-audience>
            <span>
                <strong>ساکنین</strong>
                <small>مستأجر واحدها؛ واحدهای بدون مستأجر، مالکِ ساکن.</small>
            </span>
        </label>
        <label class="choice-item">
            <input type="radio" name="target_audience" value="owners" data-cost-audience>
            <span>
                <strong>مالکین</strong>
                <small>فقط مالکان واحدها.</small>
            </span>
        </label>
        <label class="choice-item">
            <input type="radio" name="target_audience" value="tenants" data-cost-audience>
            <span>
                <strong>مستأجرین</strong>
                <small>فقط مستأجران واحدها.</small>
            </span>
        </label>
        <label class="choice-item">
            <input type="radio" name="target_audience" value="specific_units" data-cost-audience>
            <span>
                <strong>واحدهای خاص</strong>
                <small>فقط واحدهایی که در ادامه انتخاب می‌کنید.</small>
            </span>
        </label>
    </div>
</div>

<!-- فهرست واحدها فقط برای حالت «واحدهای خاص» نمایش داده می‌شود -->
<div class="audience-units-box" style="display:none;">
    <label class="form-label">انتخاب واحدها</label>
    <?php if (empty($units)): ?>
        <p class="text-xs text-gray-400">هنوز واحدی در این ساختمان ثبت نشده است.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;padding:10px;">
            <?php foreach ($units as $u): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-dark);cursor:pointer;">
                    <input type="checkbox" name="unit_ids[]" value="<?= (int) ($u['id'] ?? 0) ?>">
                    <span>
                        واحد <?= htmlspecialchars($u['unit_number'] ?? '') ?>
                        <?php if (!empty($u['owner_name']) || !empty($u['tenant_name'])): ?>
                            <span class="text-gray-400">— <?= htmlspecialchars(trim(($u['owner_name'] ?? '') . ' / ' . ($u['tenant_name'] ?? ''), ' /')) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="text-[11px] text-gray-400 mt-1">هزینه به مالک واحد اختصاص می‌یابد و اگر واحد مالک نداشته باشد، به مستأجر.</p>
    <?php endif; ?>
</div>

<div>
    <label class="form-label">مهلت پرداخت (اختیاری)</label>
    <input type="date" name="due_date" class="form-input">
</div>
<div>
    <label class="form-label">توضیحات (اختیاری)</label>
    <textarea name="description" rows="2" class="form-input" placeholder="توضیح هزینه"></textarea>
</div>
