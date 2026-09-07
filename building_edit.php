<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$building = null;
$images = building_default_images();

// دریافت اطلاعات ساختمان
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building = $building_response['data'] ?? null;
    }
}

if (!$building) {
    $page_title = 'ویرایش ساختمان';
    $header_sub = 'ساختمان یافت نشد';
    $back_url = 'index.php';
    $active_nav = 'home';
    require_once 'includes/page_head.php';
    echo '<main class="p-5"><div class="card empty-state"><div class="text-4xl mb-3">🏢</div>ساختمان موردنظر یافت نشد.</div></main>';
    require_once 'includes/page_tail.php';
    exit;
}

// ذخیره تغییرات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    if ($name === '' || $address === '') {
        $alert_message = 'نام و آدرس ساختمان الزامی است.';
    } else {
        $payload = [
            'name' => $name,
            'address' => $address,
            'custom_name' => trim($_POST['custom_name'] ?? ''),
            'theme_color' => trim($_POST['theme_color'] ?? '#1a73e8'),
            'total_units' => en_digits($_POST['total_units'] ?? '') !== '' ? max(0, (int) en_digits($_POST['total_units'])) : null,
            'total_floors' => en_digits($_POST['total_floors'] ?? '') !== '' ? max(0, (int) en_digits($_POST['total_floors'])) : null,
            'has_blocks' => isset($_POST['has_blocks']) && $_POST['has_blocks'] === '1',
            'default_image' => in_array(($_POST['default_image'] ?? 'b1'), ['b1', 'b2', 'b3', 'b4'], true) ? $_POST['default_image'] : 'b1',
            'parking_spots' => max(0, (int) en_digits($_POST['parking_spots'] ?? 0)),
            'monthly_charge' => max(0, (float) en_digits($_POST['monthly_charge'] ?? 0)),
            'monthly_charge_enabled' => !empty($_POST['monthly_charge_enabled']),
            'charge_mode' => in_array(($_POST['charge_mode'] ?? 'fixed'), ['fixed', 'per_person', 'custom', 'combined'], true) ? $_POST['charge_mode'] : 'fixed',
            'charge_per_person' => max(0, (float) en_digits($_POST['charge_per_person'] ?? 0)),
        ];
        $response = callAPI('PUT', '/buildings/' . $building_id, $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'اطلاعات ساختمان با موفقیت به‌روزرسانی شد.';
            $alert_type = 'success';
            $building = array_merge($building, $payload);
        } else {
            $alert_message = $response['message'] ?? 'خطا در ذخیره تغییرات.';
        }
    }
}

$has_blocks_checked = !empty($building['has_blocks']) ? 'checked' : '';
$monthly_checked = !empty($building['monthly_charge_enabled']) ? 'checked' : '';
$charge_mode = $building['charge_mode'] ?? 'fixed';
$current_image = $building['default_image'] ?? 'b1';

$page_title = 'ویرایش ساختمان';
$header_sub = $building['name'] ?? 'ویرایش اطلاعات';
$back_url = 'dashboard.php?building_id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <div class="card p-5">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            ویرایش اطلاعات ساختمان
        </h3>
        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <div>
                <label for="name" class="form-label">نام ساختمان *</label>
                <input type="text" id="name" name="name" required class="form-input" value="<?= htmlspecialchars($building['name'] ?? '') ?>" placeholder="مثال: برج آسمان">
            </div>
            <div>
                <label for="address" class="form-label">آدرس *</label>
                <textarea id="address" name="address" rows="2" required class="form-input" placeholder="آدرس کامل"><?= htmlspecialchars($building['address'] ?? '') ?></textarea>
            </div>
            <div>
                <label for="custom_name" class="form-label">نام سفارشی (اختیاری)</label>
                <input type="text" id="custom_name" name="custom_name" class="form-input" value="<?= htmlspecialchars($building['custom_name'] ?? '') ?>" placeholder="مثلاً: برج آسمان — بلوک A">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="total_units" class="form-label">تعداد واحد</label>
                    <input type="number" id="total_units" name="total_units" min="0" inputmode="numeric" class="form-input" value="<?= htmlspecialchars((string) ($building['total_units'] ?? '')) ?>" placeholder="مثال: 20">
                </div>
                <div>
                    <label for="total_floors" class="form-label">تعداد طبقه</label>
                    <input type="number" id="total_floors" name="total_floors" min="0" inputmode="numeric" class="form-input" value="<?= htmlspecialchars((string) ($building['total_floors'] ?? '')) ?>" placeholder="مثال: 5">
                </div>
            </div>

            <div class="card bg-gray-50 p-4">
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" name="has_blocks" value="1" class="rounded" <?= $has_blocks_checked ?>>
                    ساختمان بلوک دارد
                </label>
                <p class="text-[11px] text-gray-400 mt-2">مدیریت بلوک‌ها، طبقات، واحدها و مشاعات از صفحه ساختمان:</p>
                <div class="grid grid-cols-2 gap-2 mt-2 text-center text-xs font-bold">
                    <a href="blocks.php?building_id=<?= $building_id ?>" class="bg-white border rounded-xl py-2.5 text-indigo-600">بلوک‌ها</a>
                    <a href="floors.php?building_id=<?= $building_id ?>" class="bg-white border rounded-xl py-2.5 text-emerald-600">طبقات</a>
                    <a href="units.php?building_id=<?= $building_id ?>" class="bg-white border rounded-xl py-2.5 text-amber-600">واحدها</a>
                    <a href="common_areas.php?building_id=<?= $building_id ?>" class="bg-white border rounded-xl py-2.5 text-violet-600">مشاعات</a>
                </div>
            </div>

            <div>
                <label class="form-label">عکس ساختمان</label>
                <div class="grid grid-cols-4 gap-2">
                    <?php foreach ($images as $key => $src): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="default_image" value="<?= $key ?>" class="hidden peer" <?= $current_image === $key ? 'checked' : '' ?>>
                            <img src="<?= htmlspecialchars($src) ?>" alt="<?= $key ?>"
                                 class="rounded-xl border-2 border-transparent peer-checked:border-blue-600 h-16 w-full object-cover">
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="flex items-center gap-3 mt-3">
                    <label for="theme_color" class="form-label" style="margin:0;">رنگ تم</label>
                    <input type="color" id="theme_color" name="theme_color" class="form-input" style="width: 56px; height: 44px; padding: 4px;" value="<?= htmlspecialchars($building['theme_color'] ?? '#1a73e8') ?>">
                </div>
            </div>

            <div>
                <label for="parking_spots" class="form-label">ظرفیت پارکینگ (خودرو)</label>
                <input type="number" id="parking_spots" name="parking_spots" min="0" class="form-input" value="<?= (int) ($building['parking_spots'] ?? 0) ?>">
            </div>

            <div class="card p-4" style="background:#ecfdf5;border:1px solid #d1fae5;">
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" name="monthly_charge_enabled" value="1" class="rounded" <?= $monthly_checked ?>>
                    شارژ ماهیانه فعال باشد
                </label>

                <p class="text-xs font-bold text-gray-500" style="margin:14px 0 8px;">روش محاسبه شارژ</p>
                <div class="choice-list">
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="fixed" data-charge-mode <?= $charge_mode === 'fixed' ? 'checked' : '' ?>>
                        <span>
                            <strong>شارژ ثابت</strong>
                            <small>همه واحدها ماهانه مبلغ یکسانی پرداخت می‌کنند.</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="per_person" data-charge-mode <?= $charge_mode === 'per_person' ? 'checked' : '' ?>>
                        <span>
                            <strong>بر اساس تعداد نفرات</strong>
                            <small>شارژ هر واحد = نرخ هر نفر × تعداد ساکنین آن واحد.</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="combined" data-charge-mode <?= $charge_mode === 'combined' ? 'checked' : '' ?>>
                        <span>
                            <strong>ترکیبی: ثابت + نفری</strong>
                            <small>شارژ هر واحد = مبلغ ثابت + (تعداد ساکنین × نرخ هر نفر).</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="custom" data-charge-mode <?= $charge_mode === 'custom' ? 'checked' : '' ?>>
                        <span>
                            <strong>دلخواه</strong>
                            <small>برای هر واحد مبلغ اختصاصی در صفحه «واحدها» تعیین می‌شود.</small>
                        </span>
                    </label>
                </div>

                <div class="mt-3" data-charge-field="fixed,combined">
                    <label for="monthly_charge" class="form-label">مبلغ شارژ ثابت هر ماه (تومان)</label>
                    <input type="number" id="monthly_charge" name="monthly_charge" min="0" step="1000" inputmode="numeric" class="form-input" value="<?= htmlspecialchars((string) ($building['monthly_charge'] ?? 0)) ?>">
                </div>

                <div class="mt-3" data-charge-field="per_person,combined">
                    <label for="charge_per_person" class="form-label">نرخ شارژ هر نفر (تومان)</label>
                    <input type="number" id="charge_per_person" name="charge_per_person" min="0" step="1000" inputmode="numeric" class="form-input" value="<?= htmlspecialchars((string) ($building['charge_per_person'] ?? 0)) ?>">
                    <p class="text-[11px] text-gray-500 mt-1">تعداد نفرات هر واحد را در صفحه «واحدها» وارد کنید.</p>
                </div>

                <div class="mt-3 hint-card" data-charge-field="custom">
                    مبلغ اختصاصی هر واحد را از صفحه «مدیریت واحدها» وارد کنید.
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    ذخیره تغییرات
                </button>
                <a href="dashboard.php?building_id=<?= $building_id ?>" class="btn-secondary flex-1 text-center">انصراف</a>
            </div>
        </form>
    </div>

    <!-- حذف ساختمان -->
    <div class="card p-5 mt-6 border border-red-200">
        <h3 class="font-bold text-red-600 mb-2">حذف ساختمان</h3>
        <p class="text-xs text-gray-500 mb-4">با حذف ساختمان، دسترسی شما به آن برای همیشه از بین می‌رود. این عملیات قابل بازگشت نیست.</p>
        <form method="POST" action="building_delete.php" data-confirm="آیا مطمئن هستید؟ این ساختمان و تمام داده‌های آن حذف می‌شود.">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="id" value="<?= $building_id ?>">
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                حذف ساختمان
            </button>
        </form>
    </div>

</main>

<script>
    /* نمایش فیلد مناسب بر اساس روش محاسبه شارژ */
    (function () {
        var modes = document.querySelectorAll('[data-charge-mode]');
        var fields = document.querySelectorAll('[data-charge-field]');
        if (!modes.length) { return; }
        function sync() {
            var selected = document.querySelector('[data-charge-mode]:checked');
            var value = selected ? selected.value : 'fixed';
            fields.forEach(function (field) {
                var modes = field.getAttribute('data-charge-field').split(',');
                field.style.display = modes.indexOf(value) !== -1 ? '' : 'none';
            });
        }
        modes.forEach(function (m) { m.addEventListener('change', sync); });
        sync();
    })();
</script>

<?php require_once 'includes/page_tail.php'; ?>
