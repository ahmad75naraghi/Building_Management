<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';

// ارسال دعوت‌نامه (نام + شماره موبایل + نقش + واحد) همراه با پیامک لینک دعوت
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    } else {
        $invited_name = trim($_POST['invited_name'] ?? '');
        $invited_phone = normalize_phone($_POST['invited_phone'] ?? '');
        if ($invited_name === '') {
            $alert_message = 'نام و نام خانوادگی دعوت‌شونده را وارد کنید.';
        } elseif (!is_valid_phone($invited_phone)) {
            $alert_message = 'شماره موبایل معتبر نیست. مثال: 09123456789';
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
            }
        }
    }
}

// دریافت لیست اعضا
$members = [];
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
    $units = [];
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
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- دکمه دعوت -->
    <a href="#invite-form"
       class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>دعوت عضو جدید</span>
    </a>

    <!-- لیست اعضا -->
    <h2 class="section-title">ساکنین و مدیران (<?= fa_digits(count($members)) ?> نفر)</h2>

    <?php if (empty($members)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">👥</div>
            هنوز عضوی در ساختمان ثبت نشده است.<br>
            با دکمه بالا اولین دعوت‌نامه را ارسال کنید.
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
    <?php if (!empty($pending_invitations)): ?>
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
                                    • انقضا: <?= htmlspecialchars($inv['expires_at']) ?>
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
                                <input type="hidden" name="form_action" value="resend_sms">
                                <input type="hidden" name="invitation_id" value="<?= (int) ($inv['id'] ?? 0) ?>">
                                <button type="submit" class="text-xs bg-green-50 hover:bg-green-100 text-green-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                    ارسال مجدد پیامک
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

    <!-- فرم دعوت -->
    <div id="invite-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            ارسال دعوت‌نامه
        </h3>
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="form_action" value="invite">
            <div>
                <label for="invited_name" class="form-label">نام و نام خانوادگی *</label>
                <input type="text" id="invited_name" name="invited_name" required class="form-input" placeholder="مثال: رضا محمدی">
            </div>
            <div>
                <label for="invited_phone" class="form-label">شماره موبایل (برای ارسال پیامک لینک دعوت) *</label>
                <input type="tel" id="invited_phone" name="invited_phone" dir="ltr" required inputmode="numeric" class="form-input text-left" placeholder="09123456789">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="role" class="form-label">نقش</label>
                    <select id="role" name="role" class="form-input">
                        <option value="tenant">مستأجر</option>
                        <option value="owner">مالک</option>
                        <option value="resident">ساکن</option>
                        <option value="board">هیئت مدیره</option>
                    </select>
                </div>
                <div>
                    <label for="unit_id" class="form-label">واحد (اختیاری)</label>
                    <select id="unit_id" name="unit_id" class="form-input">
                        <option value="">— انتخاب واحد —</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= (int) ($unit['id'] ?? 0) ?>">
                                واحد <?= htmlspecialchars($unit['unit_number'] ?? '') ?>
                                (<?= htmlspecialchars(unit_type_label($unit['type'] ?? '')) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1">برای مالک/مستأجر، هنگام پذیرش دعوت‌نامه به‌صورت خودکار به واحد متصل می‌شود.</p>
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
                ارسال دعوت‌نامه
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
