<?php

/* ============================================================
 * کامپوننت پاپ‌آپ (Modal) مشترک همه صفحات
 *
 * به‌جای اینکه فرم‌های «ثبت جدید» زیر لیست رها شوند، همه آن‌ها
 * داخل یک پاپ‌آپ استاندارد نمایش داده می‌شوند.
 *
 * نحوه استفاده:
 *
 *   modal_start('add-announcement', 'ثبت اطلاعیه جدید', 'اطلاع‌رسانی به ساکنین');
 *   ... محتوای فرم ...
 *   modal_end();
 *
 * و برای باز کردن آن:
 *   <button class="btn-add-primary" data-modal-open="add-announcement">...</button>
 * ============================================================ */

if (!function_exists('modal_start')) {
    /**
     * شروع پاپ‌آپ.
     */
    function modal_start(string $id, string $title, string $subtitle = ''): void
    {
        ?>
        <div class="modal-overlay" id="<?= htmlspecialchars($id) ?>" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?= htmlspecialchars($title) ?>">
            <div class="modal-sheet">
                <div class="modal-header">
                    <div>
                        <div class="modal-title"><?= htmlspecialchars($title) ?></div>
                        <?php if ($subtitle !== ''): ?>
                            <div class="modal-subtitle"><?= htmlspecialchars($subtitle) ?></div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="modal-close" data-modal-close aria-label="بستن">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
        <?php
    }

    /**
     * پایان پاپ‌آپ.
     */
    function modal_end(): void
    {
        ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * دکمه استاندارد «افزودن» که یک پاپ‌آپ را باز می‌کند.
     */
    function modal_open_button(string $modalId, string $label): void
    {
        ?>
        <button type="button" class="btn-add-primary" data-modal-open="<?= htmlspecialchars($modalId) ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19" />
                <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            <span><?= htmlspecialchars($label) ?></span>
        </button>
        <?php
    }
}
