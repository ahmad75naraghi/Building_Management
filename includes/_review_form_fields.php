<?php /* فیلدهای مشترک نظر — پاپ‌آپ‌های reviews.php ($review_categories) */ ?>
<div>
    <label class="form-label">امتیاز *</label>
    <div class="rating-stars" dir="ltr">
        <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" id="rating_<?= $i ?>_<?= $modal_uid ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
            <label for="rating_<?= $i ?>_<?= $modal_uid ?>" title="<?= $i ?>">★</label>
        <?php endfor; ?>
    </div>
</div>
<div>
    <label class="form-label">دسته‌بندی (اختیاری)</label>
    <select name="category_id" class="form-input">
        <option value="0">— بدون دسته‌بندی —</option>
        <?php foreach ($review_categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name'] ?? '') ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div>
    <label class="form-label">متن نظر</label>
    <textarea name="review_text" rows="4" class="form-input" placeholder="نظر خود را درباره مدیریت ساختمان بنویسید..."></textarea>
</div>
