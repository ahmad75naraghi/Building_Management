<?php /* فیلدهای مشترک طبقه — پاپ‌آپ‌های floors.php ($blocks) */ ?>
<div>
    <label class="form-label">شماره طبقه *</label>
    <input type="text" name="floor_number" required inputmode="numeric" class="form-input" placeholder="مثال: 1 یا 0 برای همکف">
</div>
<div>
    <label class="form-label">نام طبقه (اختیاری)</label>
    <input type="text" name="name" class="form-input" placeholder="مثال: طبقه اول">
</div>
<?php if (!empty($blocks)): ?>
    <div>
        <label class="form-label">بلوک (اختیاری)</label>
        <select name="block_id" class="form-input">
            <option value="0">— بدون بلوک —</option>
            <?php foreach ($blocks as $b): ?>
                <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>
