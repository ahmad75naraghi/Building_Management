<?php
/* ============================================================
 * فوتر مشترک همه صفحات — نسخه استاندارد (includes/footer.php)
 * شامل: ناوبری پایین (همان index.php) + لود assets/js/main.js
 *
 * متغیرهای اختیاری:
 *   $nav_active  آیتم فعال: home|dashboard|tickets|profile|messages|none
 *   $unread_nav  تعداد اعلانات خوانده‌نشده برای نشان «پیام‌ها»
 *   $standalone  اگر true باشد فقط بستن تگ‌ها بدون ناوبری
 * ============================================================ */

$nav_active = $nav_active ?? ($active_nav ?? 'none');
$unread_nav = $unread_nav ?? 0;
$standalone = $standalone ?? false;

// «خانه» در ناوبری استاندارد همان داشبورد است
$is_dashboard = in_array($nav_active, ['dashboard', 'home'], true);
// در صفحه فهرست ساختمان‌ها، آخرین آیتم ناوبری «ساختمان‌ها» می‌شود
$is_buildings = ($nav_active === 'buildings');
// اگر صفحه ساختمان فعال دارد، لینک‌های ناوبری همان ساختمان را حفظ می‌کنند
$nav_building_id = (int) ($nav_building_id ?? ($building_id ?? 0));
$nav_qs = $nav_building_id > 0 ? '?building_id=' . $nav_building_id : '';
$nav_last_url = $is_buildings ? 'index.php' : ('dashboard.php' . $nav_qs);
$nav_last_label = $is_buildings ? 'ساختمان‌ها' : 'داشبورد';
?>
        <?php if (!$standalone): ?>

        <!-- ناوبری پایین صفحه (Navigation Bar) -->
        <nav class="bottom-nav-bar">
            <!-- پروفایل -->
            <a href="profile.php" class="nav-item-link<?= $nav_active === 'profile' ? ' active' : '' ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                <span>پروفایل</span>
            </a>

            <!-- پیام‌ها -->
            <a href="notifications.php" class="nav-item-link<?= $nav_active === 'messages' ? ' active' : '' ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
                <span>پیام‌ها</span>
                <?php if ($unread_nav > 0): ?>
                    <span class="nav-badge" style="right: 18px;"><?= fa_digits($unread_nav) ?></span>
                <?php endif; ?>
            </a>

            <!-- دکمه شناور وسط -->
            <div class="floating-action-button" onclick="window.location.href='building_add.php'" style="cursor:pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </div>

            <!-- تقویم -->
            <a href="calendar.php<?= $nav_qs ?>" class="nav-item-link<?= $nav_active === 'calendar' ? ' active' : '' ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                </svg>
                <span>تقویم</span>
            </a>

            <!-- داشبورد / ساختمان‌ها -->
            <a href="<?= $nav_last_url ?>" class="nav-item-link<?= ($is_dashboard || $is_buildings) ? ' active' : '' ?>">
                <div class="active-pill-box">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                    <span><?= $nav_last_label ?></span>
                </div>
            </a>
        </nav>

        <?php endif; ?>

    </div><!-- /.app-container -->

    <!-- اسکریپت مشترک همه صفحات -->
    <script src="assets/js/main.js"></script>
</body>

</html>
