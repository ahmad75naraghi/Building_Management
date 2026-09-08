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

<!-- هزینهٔ دوره‌ای با تناوب دلخواه؛ کران روزانه در هر نوبت آن را صادر می‌کند -->
<div style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:var(--text-dark);cursor:pointer;">
        <input type="checkbox" name="is_recurring" value="1" data-recurring-toggle>
        🔁 این هزینه دوره‌ای است و تکرار می‌شود
    </label>
    <div data-recurring-fields style="display:none;margin-top:10px;">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="form-label">هر چند وقت یک‌بار؟</label>
                <select name="recurring_interval" class="form-input">
                    <option value="weekly">هفتگی (هر هفته)</option>
                    <option value="biweekly">دو هفته یک‌بار</option>
                    <option value="monthly" selected>ماهانه</option>
                    <option value="bimonthly">دو ماه یک‌بار</option>
                    <option value="quarterly">سه ماه یک‌بار</option>
                    <option value="yearly">سالانه</option>
                </select>
            </div>
            <div>
                <label class="form-label">شروع از تاریخ *</label>
                <input type="date" name="recurring_start_date" class="form-input" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div style="margin-top:8px;">
            <label class="form-label">پایان دوره (اختیاری — خالی یعنی بدون پایان)</label>
            <input type="date" name="recurring_end_date" class="form-input">
        </div>
        <p class="text-[11px] text-gray-400 mt-2">
            در هر نوبت، یک هزینهٔ جداگانه ساخته و برای مخاطبان صادر می‌شود؛ کران روزانه (یا دکمهٔ «اجرای موتور دوره‌ای» در صفحه حسابداری) آن را انجام می‌دهد.
        </p>
    </div>
</div>
<div>
    <label class="form-label">توضیحات (اختیاری)</label>
    <textarea name="description" rows="2" class="form-input" placeholder="توضیح هزینه"></textarea>
</div>
