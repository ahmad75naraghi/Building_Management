<?php
// پروفایل کاربری — با قالب استاندارد اپ
require_once 'includes/api_helper.php';

// اگر کاربر لاگین نیست، به صفحه ورود هدایت شود
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

// دریافت اطلاعات کاربر از API
$me_response = callAPI('GET', '/auth/me');
$user_name = $me_response['data']['name'] ?? ($_SESSION['user_name'] ?? 'کاربر');
$user_email = $me_response['data']['email'] ?? '';
$user_phone = $me_response['data']['phone'] ?? '';
$first_letter = mb_substr($user_name, 0, 1, 'UTF-8');
$my_user_id = (int) ($_SESSION['user_id'] ?? ($me_response['data']['id'] ?? 0));

// ساختمان‌های من: نقش + واحدهای مرتبط کاربر در هر ساختمان
$my_buildings = [];
$buildings_response = callAPI('GET', '/buildings');
if (!empty($buildings_response['success']) && is_array($buildings_response['data'] ?? null)) {
    foreach ($buildings_response['data'] as $bld) {
        $bid = (int) ($bld['id'] ?? 0);
        if ($bid <= 0) {
            continue;
        }
        $entry = [
            'id' => $bid,
            'name' => (string) ($bld['name'] ?? 'ساختمان'),
            'role' => (string) ($bld['my_role'] ?? 'resident'),
            'units' => [],
        ];
        $units_response = callAPI('GET', '/buildings/' . $bid . '/units');
        if (!empty($units_response['success'])) {
            foreach ($units_response['data']['units'] ?? [] as $u) {
                $is_owner = (int) ($u['owner_user_id'] ?? 0) === $my_user_id;
                $is_tenant = (int) ($u['tenant_user_id'] ?? 0) === $my_user_id;
                if ($is_owner || $is_tenant) {
                    $entry['units'][] = [
                        'number' => (string) ($u['unit_number'] ?? ''),
                        'relation' => $is_owner ? 'مالک' : 'مستأجر',
                    ];
                }
            }
        }
        $my_buildings[] = $entry;
    }
}

$page_title = 'پروفایل من';
$header_sub = $user_phone ?: ($user_email ?: 'حساب کاربری');
$back_url = 'index.php';
$nav_active = 'profile';
require_once 'includes/header.php';
?>

        <!-- کارت پروفایل کاربر -->
        <section class="building-hero-card" style="margin-top: 8px;">
            <div class="building-details-wrapper" style="align-items: center; text-align: center;">
                <div class="building-header-row" style="justify-content: center;">
                    <div class="w-20 h-20 rounded-full bg-white p-1" style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.15);">
                        <div class="w-full h-full rounded-full" style="background: linear-gradient(135deg, var(--gold-primary), var(--gold-secondary)); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 900; color: #0a1931;">
                            <?= htmlspecialchars($first_letter) ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <h2 style="font-size: 17px; font-weight: 800; color: var(--text-white);"><?= htmlspecialchars($user_name) ?></h2>
                    <?php if (!empty($user_phone)): ?>
                        <p style="font-size: 12px; color: var(--text-muted-white); margin-top: 3px;" dir="ltr"><?= htmlspecialchars($user_phone) ?></p>
                        <p style="font-size: 10px; color: var(--text-muted-white); margin-top: 2px;">نام کاربری شما</p>
                    <?php endif; ?>
                    <?php if (!empty($user_email)): ?>
                        <p style="font-size: 11px; color: var(--text-muted-white); margin-top: 2px;" dir="ltr"><?= htmlspecialchars($user_email) ?></p>
                    <?php endif; ?>
                </div>
                <span style="display: inline-block; margin-top: 10px; background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); padding: 4px 14px; border-radius: 999px; font-size: 11px; font-weight: 700;">حساب فعال</span>
            </div>
        </section>

        <!-- ساختمان‌های من: نقش و واحدهای مرتبط -->
        <section class="quick-access-section" style="margin-top: 20px;">
            <div class="section-header-row">
                <span class="section-title">ساختمان‌های من</span>
                <a href="index.php" class="widget-view-all-link">لیست ساختمان‌ها</a>
            </div>

            <?php if (empty($my_buildings)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🏢</div>
                    هنوز عضو ساختمانی نیستید.
                    <a class="empty-action" href="building_add.php">🏢 ثبت ساختمان جدید</a>
                </div>
            <?php else: ?>
                <?php foreach ($my_buildings as $mb): ?>
                    <a href="dashboard.php?building_id=<?= $mb['id'] ?>" class="info-row">
                        <div class="info-row-right">
                            <div class="info-row-icon" style="background: #eff6ff; color: #3b82f6;">🏢</div>
                            <span class="info-row-label">
                                <?= htmlspecialchars($mb['name']) ?>
                                <span style="display:block; font-size:10px; color:var(--text-gray); font-weight:600; margin-top:2px;">
                                    نقش: <?= htmlspecialchars(member_role_label($mb['role'])) ?>
                                    <?php if (!empty($mb['units'])): ?>
                                        · <?php foreach ($mb['units'] as $i => $un): ?><?= $i > 0 ? '، ' : '' ?>واحد <?= fa_digits(htmlspecialchars($un['number'])) ?> (<?= htmlspecialchars($un['relation']) ?>)<?php endforeach; ?>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </div>
                        <span class="info-chevron">❯</span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- بخش اطلاعات حساب -->
        <section class="quick-access-section" style="margin-top: 20px;">
            <div class="section-header-row">
                <span class="section-title">حساب کاربری</span>
            </div>
            <a href="profile_edit.php" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #eef2ff; color: #6366f1;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z" />
                        </svg>
                    </div>
                    <span class="info-row-label">ویرایش مشخصات</span>
                </div>
                <span class="info-chevron">❯</span>
            </a>
            <a href="notifications.php" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #f5f3ff; color: #8b5cf6;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                        </svg>
                    </div>
                    <span class="info-row-label">اعلانات من</span>
                </div>
                <span class="info-chevron">❯</span>
            </a>
        </section>

        <!-- بخش تنظیمات و پشتیبانی -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">تنظیمات و پشتیبانی</span>
            </div>
            <!-- اعلان فوری وب (پوش مرورگر) -->
            <div class="info-row" id="push-toggle-btn" style="cursor:pointer;" title="فعال یا غیرفعال‌کردن اعلان فوری روی این دستگاه">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #eef2ff; color: #6366f1;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            <path d="M2 8c0-2.2.7-4.3 2-6" />
                            <path d="M22 8a10 10 0 0 0-2-6" />
                        </svg>
                    </div>
                    <span class="info-row-label">اعلان فوری روی گوشی</span>
                </div>
                <span class="push-state-badge" id="push-toggle-state">…</span>
            </div>
            <a href="change_password.php" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #fefce8; color: #eab308;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </div>
                    <span class="info-row-label">تغییر رمز عبور</span>
                </div>
                <span class="info-chevron">❯</span>
            </a>
            <a href="logout.php" class="info-row" style="border: 1px solid rgba(239, 68, 68, 0.15);">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #fef2f2; color: #ef4444;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                    </div>
                    <span class="info-row-label" style="color: #ef4444; font-weight: 800;">خروج از حساب</span>
                </div>
                <span class="info-chevron">❯</span>
            </a>
        </section>

        <p style="text-align: center; font-size: 11px; color: var(--text-gray); margin: 24px 0 8px;">نسخه ۱.۰.۰</p>

<script src="assets/js/push.js"></script>
<?php require_once 'includes/footer.php'; ?>
