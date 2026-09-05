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

// دریافت لیست بلوک‌ها، طبقات و اعضا (برای انتخاب در فرم)
$blocks = [];
$floors = [];
$members = [];
if ($building_id > 0) {
    $blocks_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (isset($blocks_response['success']) && $blocks_response['success'] === true) {
        $blocks = $blocks_response['data']['blocks'] ?? [];
    }
    $floors_response = callAPI('GET', '/buildings/' . $building_id . '/floors');
    if (isset($floors_response['success']) && $floors_response['success'] === true) {
        $floors = $floors_response['data']['floors'] ?? [];
    }
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (isset($members_response['success']) && $members_response['success'] === true) {
        $members = $members_response['data'] ?? [];
    }
}

// ثبت / ویرایش / حذف واحد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        $unit_id = (int) ($_POST['unit_id'] ?? 0);
        if ($unit_id > 0) {
            $response = callAPI('DELETE', '/units/' . $unit_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'واحد با موفقیت حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف واحد.';
            }
        }
    } else {
        $unit_number = trim($_POST['unit_number'] ?? '');
        if ($unit_number === '') {
            $alert_message = 'شماره واحد را وارد کنید.';
        } else {
            $payload = [
                'unit_number' => $unit_number,
                'type' => $_POST['type'] ?? 'residential',
                'area' => !empty($_POST['area']) ? (float) $_POST['area'] : null,
                'block_id' => !empty($_POST['block_id']) ? (int) $_POST['block_id'] : null,
                'floor_id' => !empty($_POST['floor_id']) ? (int) $_POST['floor_id'] : null,
                'owner_user_id' => !empty($_POST['owner_user_id']) ? (int) $_POST['owner_user_id'] : null,
                'tenant_user_id' => !empty($_POST['tenant_user_id']) ? (int) $_POST['tenant_user_id'] : null,
                'owner_resident' => !empty($_POST['owner_resident']) ? 1 : 0,
            ];
            if ($action === 'update') {
                $unit_id = (int) ($_POST['unit_id'] ?? 0);
                if ($unit_id <= 0) {
                    $alert_message = 'شناسه واحد نامعتبر است.';
                } else {
                    $response = callAPI('PUT', '/units/' . $unit_id, $payload);
                    if (isset($response['success']) && $response['success'] === true) {
                        $alert_message = 'واحد با موفقیت ویرایش شد.';
                        $alert_type = 'success';
                    } else {
                        $alert_message = $response['message'] ?? 'خطا در ویرایش واحد.';
                    }
                }
            } else {
                $response = callAPI('POST', '/buildings/' . $building_id . '/units', $payload);
                if (isset($response['success']) && $response['success'] === true) {
                    $alert_message = 'واحد با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت واحد. لطفاً دوباره تلاش کنید.';
                }
            }
        }
    }
}

// دریافت اطلاعات ساختمان و لیست واحدها
$units = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $units = $list_response['data']['units'] ?? [];
    }
}

$block_names = [];
foreach ($blocks as $b) {
    $block_names[$b['id']] = $b['name'];
}
$floor_names = [];
foreach ($floors as $f) {
    $floor_names[$f['id']] = 'طبقه ' . ($f['floor_number'] ?? '');
}

// ---------- حالت ویرایش ----------
$editing_unit = null;
$edit_unit_id = (int) ($_GET['edit_unit_id'] ?? 0);
if ($edit_unit_id > 0) {
    foreach ($units as $unit) {
        if ((int) ($unit['id'] ?? 0) === $edit_unit_id) {
            $editing_unit = $unit;
            break;
        }
    }
}

$occupancy_labels = [
    'owner_occupied' => 'مالک ساکن',
    'tenant_occupied' => 'مستأجر ساکن',
    'vacant' => 'خالی (مالک غیرساکن)',
    'no_owner' => 'بدون مالک',
];
$occupancy_colors = [
    'owner_occupied' => 'bg-emerald-50 text-emerald-700',
    'tenant_occupied' => 'bg-amber-50 text-amber-700',
    'vacant' => 'bg-gray-100 text-gray-600',
    'no_owner' => 'bg-red-50 text-red-600',
];
$occupancy_icons = [
    'owner_occupied' => '🏠',
    'tenant_occupied' => '👥',
    'vacant' => '🔒',
    'no_owner' => '❓',
];

$page_title = 'مدیریت واحدها';
$header_sub = $building_name ?: 'ساختار مجتمع';
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <?php if ($alert_message): ?>
        <div class="mb-4 px-4 py-3 rounded-xl text-sm font-bold <?= $alert_type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600' ?>">
            <?= htmlspecialchars($alert_message) ?>
        </div>
    <?php endif; ?>

    <!-- دکمه افزودن -->
    <a href="#add-form"
       class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>افزودن واحد جدید</span>
    </a>

    <!-- لیست واحدها -->
    <h2 class="section-title">واحدهای ساختمان (<?= fa_digits(count($units)) ?> واحد)</h2>

    <?php if (empty($units)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🏠</div>
            هنوز واحدی ثبت نشده است.<br>
            با دکمه بالا اولین واحد را اضافه کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($units as $unit): ?>
                <?php
                $status = $unit['occupancy_status'] ?? 'no_owner';
                $is_occupied = !empty($unit['is_occupied']);
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-yellow-50 text-yellow-600 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-bold text-gray-800">واحد <?= htmlspecialchars($unit['unit_number'] ?? '—') ?></h3>
                                <span class="text-[10px] px-2 py-0.5 rounded-full <?= $occupancy_colors[$status] ?? 'bg-gray-100 text-gray-600' ?> flex-shrink-0">
                                    <?= $occupancy_icons[$status] ?? '' ?> <?= htmlspecialchars($occupancy_labels[$status] ?? $status) ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 mt-0.5 truncate">
                                <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full inline-block ml-1"><?= htmlspecialchars(unit_type_label($unit['type'] ?? '')) ?></span>
                                <?php if (!empty($unit['area'])): ?>
                                    متراژ: <?= fa_number($unit['area']) ?> متر
                                <?php endif; ?>
                                <?php if (!empty($unit['floor_id']) && isset($floor_names[$unit['floor_id']])): ?>
                                    • <?= htmlspecialchars($floor_names[$unit['floor_id']]) ?>
                                <?php endif; ?>
                                <?php if (!empty($unit['block_id']) && isset($block_names[$unit['block_id']])): ?>
                                    • بلوک <?= htmlspecialchars($block_names[$unit['block_id']]) ?>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($unit['owner_name']) || !empty($unit['tenant_name'])): ?>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <?php if (!empty($unit['owner_name'])): ?>
                                        <span class="text-[11px] px-2 py-1 rounded-lg bg-blue-50 text-blue-700">
                                            مالک: <?= htmlspecialchars($unit['owner_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($unit['tenant_name'])): ?>
                                        <span class="text-[11px] px-2 py-1 rounded-lg bg-amber-50 text-amber-700">
                                            مستأجر: <?= htmlspecialchars($unit['tenant_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($is_occupied && !empty($unit['occupant_name'])): ?>
                                        <span class="text-[11px] px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700">
                                            ساکن: <?= htmlspecialchars($unit['occupant_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <a href="?building_id=<?= $building_id ?>&edit_unit_id=<?= (int) ($unit['id'] ?? 0) ?>#edit-form"
                           class="flex-1 text-center text-xs bg-gray-50 hover:bg-gray-100 text-gray-700 font-bold px-3 py-2 rounded-lg transition-colors">
                            ✏️ ویرایش
                        </a>
                        <form method="POST" action="?building_id=<?= $building_id ?>" class="flex-1"
                              onsubmit="return confirm('واحد <?= htmlspecialchars($unit['unit_number'] ?? '', ENT_QUOTES) ?> حذف شود؟ این عمل قابل بازگشت نیست.');">
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="unit_id" value="<?= (int) ($unit['id'] ?? 0) ?>">
                            <button type="submit" class="w-full text-center text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors">
                                🗑 حذف
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن / ویرایش -->
    <div id="edit-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
            <?= $editing_unit ? 'ویرایش واحد ' . htmlspecialchars($editing_unit['unit_number'] ?? '') : 'ثبت واحد جدید' ?>
        </h3>
        <?php if ($editing_unit): ?>
            <p class="text-xs text-gray-400 mb-4">
                <a href="?building_id=<?= $building_id ?>" class="text-blue-600 font-bold">← انصراف از ویرایش</a>
            </p>
        <?php endif; ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4">
            <input type="hidden" name="_action" value="<?= $editing_unit ? 'update' : 'create' ?>">
            <?php if ($editing_unit): ?>
                <input type="hidden" name="unit_id" value="<?= (int) ($editing_unit['id'] ?? 0) ?>">
            <?php endif; ?>
            <div>
                <label for="unit_number" class="form-label">شماره واحد *</label>
                <input type="text" id="unit_number" name="unit_number" required class="form-input" placeholder="مثال: ۱۰۱"
                       value="<?= htmlspecialchars($editing_unit['unit_number'] ?? '') ?>">
            </div>
            <div>
                <label for="type" class="form-label">نوع واحد</label>
                <select id="type" name="type" class="form-input">
                    <?php $type_val = $editing_unit['type'] ?? 'residential'; ?>
                    <option value="residential" <?= $type_val === 'residential' ? 'selected' : '' ?>>مسکونی</option>
                    <option value="commercial" <?= $type_val === 'commercial' ? 'selected' : '' ?>>تجاری</option>
                    <option value="office" <?= $type_val === 'office' ? 'selected' : '' ?>>اداری</option>
                    <option value="parking" <?= $type_val === 'parking' ? 'selected' : '' ?>>پارکینگ</option>
                    <option value="storage" <?= $type_val === 'storage' ? 'selected' : '' ?>>انباری</option>
                </select>
            </div>
            <div>
                <label for="area" class="form-label">متراژ (متر مربع)</label>
                <input type="number" id="area" name="area" step="0.1" min="0" class="form-input" placeholder="مثال: ۱۲۰"
                       value="<?= htmlspecialchars($editing_unit['area'] ?? '') ?>">
            </div>
            <?php if (!empty($blocks)): ?>
            <div>
                <label for="block_id" class="form-label">بلوک</label>
                <select id="block_id" name="block_id" class="form-input">
                    <option value="">— بدون بلوک —</option>
                    <?php foreach ($blocks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) ($editing_unit['block_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (!empty($floors)): ?>
            <div>
                <label for="floor_id" class="form-label">طبقه</label>
                <select id="floor_id" name="floor_id" class="form-input">
                    <option value="">— بدون طبقه —</option>
                    <?php foreach ($floors as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) ($editing_unit['floor_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($floor_names[$f['id']]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (!empty($members)): ?>
            <div class="border-t border-gray-100 pt-4">
                <p class="text-xs font-bold text-gray-500 mb-3">👤 ساکنین و مالکیت واحد</p>
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label for="owner_user_id" class="form-label">مالک واحد</label>
                        <select id="owner_user_id" name="owner_user_id" class="form-input">
                            <option value="">— بدون مالک —</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= (int) ($m['user_id'] ?? 0) ?>" <?= (int) ($editing_unit['owner_user_id'] ?? 0) === (int) ($m['user_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['name'] ?? 'کاربر') ?> (<?= htmlspecialchars($m['email'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="tenant_user_id" class="form-label">مستأجر</label>
                        <select id="tenant_user_id" name="tenant_user_id" class="form-input">
                            <option value="">— بدون مستأجر —</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= (int) ($m['user_id'] ?? 0) ?>" <?= (int) ($editing_unit['tenant_user_id'] ?? 0) === (int) ($m['user_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['name'] ?? 'کاربر') ?> (<?= htmlspecialchars($m['email'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                        <input type="checkbox" id="owner_resident" name="owner_resident" value="1" class="h-4 w-4 accent-blue-600"
                               <?= !empty($editing_unit['owner_resident']) ? 'checked' : '' ?>>
                        مالک در این واحد ساکن است
                        <span class="text-[10px] text-gray-400">(اگر مستأجر انتخاب شود، مستأجر ساکن محسوب می‌شود)</span>
                    </label>
                </div>
            </div>
            <?php else: ?>
                <p class="text-xs text-gray-400 bg-gray-50 border border-dashed border-gray-200 rounded-lg p-3">
                    💡 برای تعیین مالک/مستأجر/ساکن، ابتدا از صفحه «اعضای ساختمان» اعضا را اضافه کنید.
                </p>
            <?php endif; ?>

            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                <?= $editing_unit ? 'ذخیره تغییرات' : 'ذخیره واحد' ?>
            </button>
        </form>
    </div>

</main>

<script>
    // اگر مستأجر انتخاب شود، گزینه «مالک ساکن» غیرفعال می‌شود (ساکن مستأجر است)
    (function () {
        var tenantSelect = document.getElementById('tenant_user_id');
        var ownerResident = document.getElementById('owner_resident');
        if (!tenantSelect || !ownerResident) return;
        function sync() {
            if (tenantSelect.value !== '') {
                ownerResident.checked = false;
                ownerResident.disabled = true;
                ownerResident.closest('label').style.opacity = '0.5';
            } else {
                ownerResident.disabled = false;
                ownerResident.closest('label').style.opacity = '1';
            }
        }
        tenantSelect.addEventListener('change', sync);
        sync();
    })();
</script>

<?php require_once 'includes/page_tail.php'; ?>
