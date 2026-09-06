<?php
/* فیلدهای مشترک فرم ثبت/ویرایش واحد — داخل پاپ‌آپ‌های units.php استفاده می‌شود.
   متغیرهای موردنیاز: $blocks, $floors, $members, $charge_mode */
?>
<div>
    <label class="form-label">شماره واحد *</label>
    <input type="text" name="unit_number" required inputmode="numeric" class="form-input" placeholder="مثال: 101">
</div>

<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="form-label">نوع واحد</label>
        <select name="type" class="form-input">
            <option value="residential">مسکونی</option>
            <option value="commercial">تجاری</option>
            <option value="office">اداری</option>
            <option value="parking">پارکینگ</option>
            <option value="storage">انباری</option>
        </select>
    </div>
    <div>
        <label class="form-label">متراژ (متر)</label>
        <input type="number" name="area" step="0.1" min="0" inputmode="decimal" class="form-input" placeholder="مثال: 120">
    </div>
</div>

<?php if (!empty($blocks) || !empty($floors)): ?>
    <div class="grid grid-cols-2 gap-3">
        <?php if (!empty($blocks)): ?>
            <div>
                <label class="form-label">بلوک</label>
                <select name="block_id" class="form-input">
                    <option value="0">— بدون بلوک —</option>
                    <?php foreach ($blocks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if (!empty($floors)): ?>
            <div>
                <label class="form-label">طبقه</label>
                <select name="floor_id" class="form-input">
                    <option value="0">— بدون طبقه —</option>
                    <?php foreach ($floors as $f): ?>
                        <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($floor_names[$f['id']] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- مالکیت و سکونت -->
<div style="border-top:1px solid #eef2f7;padding-top:14px;">
    <p class="text-xs font-bold text-gray-500 mb-3">👤 مالکیت و سکونت</p>

    <?php if (!empty($members)): ?>
        <div class="space-y-3">
            <div>
                <label class="form-label">مالک واحد</label>
                <select name="owner_user_id" class="form-input">
                    <option value="0">— بدون مالک —</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int) ($m['user_id'] ?? 0) ?>"><?= htmlspecialchars($m['name'] ?? 'کاربر') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">مستأجر</label>
                <select name="tenant_user_id" class="form-input">
                    <option value="0">— بدون مستأجر —</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int) ($m['user_id'] ?? 0) ?>"><?= htmlspecialchars($m['name'] ?? 'کاربر') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
                <input type="checkbox" name="owner_resident" value="1" class="rounded">
                <span>
                    <span class="text-sm font-medium text-gray-700">مالک در این واحد ساکن است</span>
                    <small style="display:block;font-size:11px;color:var(--text-gray);margin-top:2px;">اگر مستأجر انتخاب شود، مستأجر ساکن محسوب می‌شود.</small>
                </span>
            </label>
        </div>
    <?php else: ?>
        <div class="hint-card">
            💡 برای تعیین مالک/مستأجر، ابتدا از صفحه «اعضای ساختمان» اعضا را دعوت کنید.
        </div>
    <?php endif; ?>
</div>

<!-- شارژ -->
<div style="border-top:1px solid #eef2f7;padding-top:14px;">
    <p class="text-xs font-bold text-gray-500 mb-3">💰 اطلاعات شارژ</p>

    <div>
        <label class="form-label">
            تعداد نفرات ساکن
            <?php if (($charge_mode ?? 'fixed') === 'per_person'): ?>
                <span style="color:var(--red-danger);">*</span>
            <?php endif; ?>
        </label>
        <input type="number" name="residents_count" min="0" inputmode="numeric" class="form-input" placeholder="مثال: 3" value="0">
        <?php if (($charge_mode ?? 'fixed') === 'per_person'): ?>
            <p class="text-[11px] text-gray-400 mt-1">شارژ این واحد = تعداد نفرات × نرخ هر نفر.</p>
        <?php endif; ?>
    </div>

    <?php if (($charge_mode ?? 'fixed') === 'custom'): ?>
        <div style="margin-top:12px;">
            <label class="form-label">شارژ اختصاصی این واحد (تومان)</label>
            <input type="number" name="custom_charge" min="0" step="1000" inputmode="numeric" class="form-input" placeholder="مثال: 450000">
        </div>
    <?php else: ?>
        <input type="hidden" name="custom_charge" value="">
    <?php endif; ?>
</div>
