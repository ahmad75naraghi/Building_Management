<?php
/* ============================================================
 * فوتر مشترک همه صفحات — نسخه استاندارد (includes/footer.php)
 * شامل: ناوبری پایین + لود assets/js/main.js
 *
 * متغیرهای اختیاری:
 *   $nav_active  آیتم فعال: dashboard|home|messages|buildings|calendar|profile|none
 *   $unread_nav  تعداد اعلانات خوانده‌نشده برای نشان «پیام‌ها»
 *   $standalone  اگر true باشد فقط بستن تگ‌ها بدون ناوبری
 * ============================================================ */

$nav_active = $nav_active ?? ($active_nav ?? 'none');
$unread_nav = $unread_nav ?? 0;
$standalone = $standalone ?? false;

// «خانه» در ناوبری استاندارد همان داشبورد است
$is_dashboard = in_array($nav_active, ['dashboard', 'home'], true);
// اگر صفحه ساختمان فعال دارد، لینک‌های ناوبری همان ساختمان را حفظ می‌کنند
$nav_building_id = (int) ($nav_building_id ?? ($building_id ?? 0));
$nav_qs = $nav_building_id > 0 ? '?building_id=' . $nav_building_id : '';
?>
        <?php if (!$standalone): ?>

        <!-- ناوبری پایین صفحه — ۵ آیتم یک‌دست، آیتم فعال هایلایت طلایی (ترتیب راست‌به‌چپ) -->
        <nav class="bottom-nav-bar" aria-label="ناوبری اصلی">
            <div class="bottom-nav-inner">

                <!-- داشبورد -->
                <a href="dashboard.php<?= $nav_qs ?>" class="nav-item-link<?= $is_dashboard ? ' active' : '' ?>">
                    <span class="nav-ico">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                    </span>
                    <span class="nav-label">داشبورد</span>
                </a>

                <!-- پیام‌های من -->
                <?php $unread_messages_nav = $unread_messages_nav ?? 0; ?>
                <a href="messages.php<?= $nav_qs ?>" class="nav-item-link<?= $nav_active === 'messages' ? ' active' : '' ?>">
                    <span class="nav-ico">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>
                        <?php if ($unread_messages_nav > 0): ?>
                            <span class="nav-badge"><?= fa_digits($unread_messages_nav) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="nav-label">پیام‌ها</span>
                </a>

                <!-- ساختمان‌های من -->
                <a href="index.php" class="nav-item-link<?= $nav_active === 'buildings' ? ' active' : '' ?>">
                    <span class="nav-ico">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="2" width="16" height="20" rx="2" />
                            <line x1="9" y1="7" x2="9" y2="7.01" />
                            <line x1="15" y1="7" x2="15" y2="7.01" />
                            <line x1="9" y1="11" x2="9" y2="11.01" />
                            <line x1="15" y1="11" x2="15" y2="11.01" />
                            <line x1="9" y1="15" x2="9" y2="15.01" />
                            <line x1="15" y1="15" x2="15" y2="15.01" />
                            <path d="M10 22v-4h4v4" />
                        </svg>
                    </span>
                    <span class="nav-label">ساختمان‌ها</span>
                </a>

                <!-- تقویم -->
                <a href="calendar.php<?= $nav_qs ?>" class="nav-item-link<?= $nav_active === 'calendar' ? ' active' : '' ?>">
                    <span class="nav-ico">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                    </span>
                    <span class="nav-label">تقویم</span>
                </a>

                <!-- حساب کاربری -->
                <a href="profile.php" class="nav-item-link<?= $nav_active === 'profile' ? ' active' : '' ?>">
                    <span class="nav-ico">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </span>
                    <span class="nav-label">حساب من</span>
                </a>

            </div>
        </nav>

        <?php endif; ?>

    </div><!-- /.app-container -->

    <!-- بنر کوچک نصب اپلیکیشن (PWA) — بعد از نصب یا ردِ یک‌هفته‌ای نمایش داده نمی‌شود -->
    <div class="pwa-banner" id="pwa-banner" role="dialog" aria-label="نصب اپلیکیشن مدیریت ساختمان">
        <div class="pwa-banner-icon"><img src="assets/icons/icon-192.png" alt=""></div>
        <div class="pwa-banner-text">
            <div class="pwa-banner-title">اپ «مدیریت ساختمان» را نصب کنید</div>
            <div class="pwa-banner-sub" id="pwa-banner-sub">دسترسی سریع‌تر، شبیه یک اپ واقعی و بدون مرورگر</div>
        </div>
        <button type="button" class="pwa-banner-install" id="pwa-banner-install">نصب</button>
        <button type="button" class="pwa-banner-close" id="pwa-banner-close" aria-label="بستن">×</button>
    </div>

    <!-- اسکریپت مشترک همه صفحات -->
    <script src="assets/js/main.js"></script>
    <!-- ارتقای تجربه: توست، نوار پیشرفت، جستجو/صفحه‌بندی فهرست‌ها -->
    <script src="assets/js/ui-enhance.js"></script>
    <!-- تقویم شمسی برای فیلدهای تاریخ (data-jalali-date) -->
    <script src="assets/js/jalali-picker.js"></script>
    <!-- منطق نصب PWA و بنر -->
    <script src="assets/js/pwa.js"></script>
</body>

</html>
