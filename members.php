<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// نقش کاربر — دعوت اعضا فقط توسط مدیر ساختمان
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// ارسال دعوت‌نامه (نام + شماره موبایل + نقش + واحد) همراه با پیامک لینک دعوت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_manager) {
    $alert_message = 'فقط مدیر ساختمان می‌تواند اعضا را دعوت کند.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? 'invite';
    if ($action === 'resend_sms') {
        $inv_id = (int) ($_POST['invitation_id'] ?? 0);
        if ($inv_id > 0 && $building_id > 0) {
            $response = callAPI('POST', '/invitations/' . $inv_id . '/resend', ['building_id' => $building_id]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'پیامک دعوت مجدداً ارسال شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'ارسال پیامک ناموفق بود.';
            }
        }
    } elseif ($action === 'revoke_invitation') {
        $inv_id = (int) ($_POST['invitation_id'] ?? 0);
        if ($inv_id > 0) {
            $response = callAPI('DELETE', '/invitations/' . $inv_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'دعوت‌نامه لغو شد و لینک آن دیگر معتبر نیست.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'لغو دعوت‌نامه ناموفق بود.';
            }
        }
    } else {
        $invited_name = trim($_POST['invited_name'] ?? '');
        $invited_phone = normalize_phone($_POST['invited_phone'] ?? '');
        if ($invited_name === '') {
            $alert_message = 'نام و نام خانوادگی دعوت‌شونده را وارد کنید.';
            $reopen_modal = 'invite-member';
        } elseif (!is_valid_phone($invited_phone)) {
            $alert_message = 'شماره موبایل معتبر نیست. مثال: 09123456789';
            $reopen_modal = 'invite-member';
        } else {
            $payload = [
                'invited_name' => $invited_name,
                'invited_phone' => $invited_phone,
                'role' => $_POST['role'] ?? 'tenant',
                'unit_id' => !empty($_POST['unit_id']) ? (int) $_POST['unit_id'] : null,
            ];
            $response = callAPI('POST', '/buildings/' . $building_id . '/invitations', $payload);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = $response['message'] ?? 'دعوت‌نامه با موفقیت ثبت و پیامک ارسال شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ارسال دعوت‌نامه.';
                $reopen_modal = 'invite-member';
            }
        }
    }
}

// دریافت لیست اعضا
$members = [];
$units = [];
$building_name = '';
$invitations = [];
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (isset($members_response['success']) && $members_response['success'] === true) {
        $members = $members_response['data'] ?? [];
    }
    // لیست واحدها (برای انتخاب واحد در فرم دعوت)
    $units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (isset($units_response['success']) && $units_response['success'] === true) {
        $units = $units_response['data']['units'] ?? [];
    }
    // دعوتنامه‌های در انتظار (برای نمایش لینک دعوت)
    $inv_response = callAPI('GET', '/buildings/' . $building_id . '/invitations');
    if (isset($inv_response['success']) && $inv_response['success'] === true) {
        $invitations = $inv_response['data'] ?? [];
    }
}

$role_labels = [
    'manager' => 'مدیر',
    'owner' => 'مالک',
    'tenant' => 'مستأجر',
    'resident' => 'ساکن',
    'board' => 'هیئت مدیره',
];

$page_title = 'اعضای ساختمان';
$header_sub = $building_name ?: 'ساکنین و مدیران';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('invite-member', 'دعوت عضو جدید'); ?>
        <a href="bulk_users.php?building_id=<?= $building_id ?>" class="btn-view-profile mt-3 inline-block text-center" style="text-decoration:none;">
            👥➕ ساخت گروهی کاربران و اتصال به واحدها
        </a>
    <?php else: ?>
        <div class="hint-card">👥 دعوت اعضای جدید فقط توسط مدیر ساختمان انجام می‌شود.</div>
    <?php endif; ?>

    <!-- لیست اعضا -->
    <h2 class="section-title">ساکنین و مدیران (<?= fa_digits(count($members)) ?> نفر)</h2>

    <?php if (empty($members)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">👥</div>
            هنوز عضوی در ساختمان ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($members as $member): ?>
                <div class="card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0 font-bold text-lg">
                        <?= htmlspecialchars(mb_substr($member['name'] ?? 'کاربر', 0, 1, 'UTF-8')) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="font-bold text-gray-800 truncate"><?= htmlspecialchars($member['name'] ?? 'بدون نام') ?></h3>
                            <?php if (($member['role'] ?? '') === 'manager'): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 flex-shrink-0">مدیر</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($member['phone'])): ?>
                            <p class="text-sm text-gray-500 mt-0.5 truncate" dir="ltr"><?= htmlspecialchars($member['phone']) ?></p>
                        <?php elseif (!empty($member['email'])): ?>
                            <p class="text-sm text-gray-500 mt-0.5 truncate" dir="ltr"><?= htmlspecialchars($member['email']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($member['units'])): ?>
                            <div class="flex flex-wrap gap-1.5 mt-1.5">
                                <?php foreach ($member['units'] as $mu): ?>
                                    <?php
                                    $rel = $mu['relation'] ?? '';
                                    $rel_label = 'واحد ' . fa_digits($mu['unit_number'] ?? '');
                                    $rel_class = 'bg-gray-100 text-gray-600';
                                    if ($rel === 'owner') {
                                        $rel_label .= ' — مالک';
                                        $rel_class = 'bg-blue-50 text-blue-700';
                                    } elseif ($rel === 'owner_resident') {
                                        $rel_label .= ' — مالک ساکن';
                                        $rel_class = 'bg-emerald-50 text-emerald-700';
                                    } elseif ($rel === 'tenant') {
                                        $rel_label .= ' — مستأجر';
                                        $rel_class = 'bg-amber-50 text-amber-700';
                                    }
                                    ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= $rel_class ?>"><?= htmlspecialchars($rel_label) ?></span>
                                    <?php if (!empty($mu['parking_no'])): ?>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600" title="قطعه پارکینگ">🚗 پارکینگ <?= fa_digits($mu['parking_no']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($mu['storage_no'])): ?>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600" title="قطعه انباری">📦 انباری <?= fa_digits($mu['storage_no']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs bg-gray-100 text-gray-500 px-2.5 py-1 rounded-full flex-shrink-0">
                        <?= htmlspecialchars($role_labels[$member['role'] ?? ''] ?? ($member['role'] ?? 'ساکن')) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- دعوت‌نامه‌های در انتظار -->
    <?php $pending_invitations = array_filter($invitations, fn($inv) => ($inv['status'] ?? '') === 'pending'); ?>
    <?php if ($is_manager && !empty($pending_invitations)): ?>
        <h2 class="section-title">دعوت‌نامه‌های در انتظار</h2>
        <div class="space-y-3">
            <?php foreach ($pending_invitations as $inv): ?>
                <?php
                $invite_link = 'invite.php?token=' . urlencode($inv['token'] ?? '');
                $contact = $inv['invited_name'] ?? $inv['invited_phone'] ?? $inv['invited_email'] ?? 'بدون مشخصات';
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($contact) ?></h3>
                            <?php if (!empty($inv['invited_phone'])): ?>
                                <p class="text-xs text-gray-500 mt-0.5" dir="ltr"><?= htmlspecialchars($inv['invited_phone']) ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 mt-1">
                                نقش: <?= htmlspecialchars($role_labels[$inv['role'] ?? ''] ?? ($inv['role'] ?? 'ساکن')) ?>
                                <?php if (!empty($inv['expires_at'])): ?>
                                    • انقضا: <?= fa_digits(jdate('j F Y', strtotime((string) $inv['expires_at']))) ?>
                                <?php endif; ?>
                            </p>
                            <p class="text-[11px] text-gray-400 mt-2 break-all" dir="ltr"><?= htmlspecialchars($invite_link) ?></p>
                        </div>
                        <div class="flex flex-col gap-2 flex-shrink-0">
                            <a href="<?= htmlspecialchars($invite_link) ?>" target="_blank" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg text-center transition-colors">
                                باز کردن
                            </a>
                            <button type="button" onclick="copyInviteLink(this, '<?= htmlspecialchars($invite_link, ENT_QUOTES) ?>')" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                کپی لینک
                            </button>
                            <form method="POST" action="" style="display:contents;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="resend_sms">
                                <input type="hidden" name="invitation_id" value="<?= (int) ($inv['id'] ?? 0) ?>">
                                <button type="submit" class="text-xs bg-green-50 hover:bg-green-100 text-green-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                    ارسال مجدد پیامک
                                </button>
                            </form>
                            <form method="POST" action="" style="display:contents;"
                                  onsubmit="return confirm('دعوت‌نامه لغو شود؟ لینک دعوت دیگر کار نخواهد کرد.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="revoke_invitation">
                                <input type="hidden" name="invitation_id" value="<?= (int) ($inv['id'] ?? 0) ?>">
                                <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors">
                                    لغو دعوت
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <script>
            function copyInviteLink(btn, link) {
                const full = window.location.origin + window.location.pathname.replace(/members\.php.*$/, '') + link;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(full).then(() => {
                        const old = btn.textContent;
                        btn.textContent = '✓ کپی شد';
                        setTimeout(() => { btn.textContent = old; }, 1500);
                    });
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = full;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    const old = btn.textContent;
                    btn.textContent = '✓ کپی شد';
                    setTimeout(() => { btn.textContent = old; }, 1500);
                }
            }
        </script>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>
    <?php modal_start('invite-member', 'ارسال دعوت‌نامه', 'لینک دعوت با پیامک ارسال می‌شود'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="invite">
            <div>
                <label class="form-label">نام و نام خانوادگی *</label>
                <input type="text" name="invited_name" required class="form-input" placeholder="مثال: رضا محمدی">
            </div>
            <div>
                <label class="form-label">شماره موبایل *</label>
                <input type="tel" name="invited_phone" dir="ltr" required inputmode="numeric" class="form-input" style="text-align:left;" placeholder="09123456789">
            </div>
            <div>
                <label class="form-label">نقش</label>
                <select name="role" class="form-input">
                    <option value="tenant">مستأجر</option>
                    <option value="owner">مالک</option>
                    <option value="resident">ساکن</option>
                    <option value="board">هیئت مدیره</option>
                </select>
            </div>
            <div>
                <label class="form-label">واحد (اختیاری)</label>
                <select name="unit_id" class="form-input">
                    <option value="">— انتخاب واحد —</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= (int) ($unit['id'] ?? 0) ?>">
                            واحد <?= fa_digits($unit['unit_number'] ?? '') ?> (<?= htmlspecialchars(unit_type_label($unit['type'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-400 mt-1">هنگام پذیرش دعوت، کاربر به‌صورت خودکار به این واحد متصل می‌شود.</p>
            </div>
            <button type="submit" class="btn-primary">ارسال دعوت‌نامه</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
