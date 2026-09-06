<?php /* فیلدهای مشترک مخاطب اضطراری — پاپ‌آپ‌های emergency_contacts.php ($role_labels لازم است) */ ?>
<div>
    <label class="form-label">نام مخاطب *</label>
    <input type="text" name="contact_name" required class="form-input" placeholder="مثال: آتش‌نشانی منطقه">
</div>
<div>
    <label class="form-label">نقش</label>
    <select name="contact_role" class="form-input">
        <?php foreach ($role_labels as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div>
    <label class="form-label">شماره تماس *</label>
    <input type="tel" name="phone" required dir="ltr" inputmode="tel" class="form-input" style="text-align:left;" placeholder="021xxxxxxx">
</div>
<div>
    <label class="form-label">ایمیل (اختیاری)</label>
    <input type="email" name="email" dir="ltr" class="form-input" style="text-align:left;" placeholder="name@example.com">
</div>
