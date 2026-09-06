<?php
// ثبت ساختمان جدید — با قالب استاندارد اپ
require_once 'includes/api_helper.php';

// اگر کاربر لاگین نیست، به صفحه ورود هدایت شود
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$alert_message = '';
$alert_type = 'error';
$images = building_default_images();

// بررسی ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $custom_name = trim($_POST['custom_name'] ?? '');

    // اعتبارسنجی — API هر دو فیلد نام و آدرس را اجباری می‌داند
    if ($name === '' || $address === '') {
        $alert_message = 'نام و آدرس ساختمان را وارد کنید.';
    } else {
        $has_blocks = isset($_POST['has_blocks']) && $_POST['has_blocks'] === '1';
        $blocks = [];
        if ($has_blocks) {
            foreach ((array) ($_POST['blocks'] ?? []) as $bname) {
                $bname = trim((string) $bname);
                if ($bname !== '') {
                    $blocks[] = $bname;
                }
            }
        }
        $common_areas = [];
        foreach ((array) ($_POST['common_areas'] ?? []) as $caname) {
            $caname = trim((string) $caname);
            if ($caname !== '') {
                $common_areas[] = ['name' => $caname, 'type' => 'general', 'bookable' => true];
            }
        }

        // ارقام فارسی را به انگلیسی تبدیل می‌کنیم تا مقادیر عددی از دست نروند
        $total_units_raw = en_digits($_POST['total_units'] ?? '');
        $total_floors_raw = en_digits($_POST['total_floors'] ?? '');
        $parking_raw = en_digits($_POST['parking_spots'] ?? '0');
        $monthly_raw = en_digits($_POST['monthly_charge'] ?? '0');

        $building_data = [
            'name' => $name,
            'address' => $address,
            'custom_name' => $custom_name !== '' ? $custom_name : null,
            'theme_color' => trim($_POST['theme_color'] ?? '#1a73e8'),
            'total_units' => $total_units_raw !== '' ? max(0, (int) $total_units_raw) : null,
            'total_floors' => $total_floors_raw !== '' ? max(0, (int) $total_floors_raw) : null,
            'has_blocks' => $has_blocks,
            'blocks' => $blocks,
            'default_image' => in_array(($_POST['default_image'] ?? 'b1'), ['b1', 'b2', 'b3', 'b4'], true) ? $_POST['default_image'] : 'b1',
            'parking_spots' => max(0, (int) $parking_raw),
            'common_areas' => $common_areas,
            'monthly_charge' => max(0, (float) $monthly_raw),
            'monthly_charge_enabled' => !empty($_POST['monthly_charge_enabled']) && (float) $monthly_raw > 0,
        ];

        $response = callAPI('POST', '/buildings', $building_data);

        if (isset($response['success']) && $response['success'] === true) {
            $new_id = (int) ($response['data']['id'] ?? 0);
            $_SESSION['active_building_id'] = $new_id;
            header("Location: dashboard.php?building_id=" . $new_id);
            exit;
        } else {
            if (isset($response['http_code']) && $response['http_code'] == 401) {
                session_destroy();
                header("Location: auth.php");
                exit;
            }
            $alert_message = $response['message'] ?? 'خطا در ثبت ساختمان. لطفاً دوباره تلاش کنید.';
        }
    }
}

$page_title = 'ثبت ساختمان';
$header_sub = 'ایجاد مجتمع جدید';
$back_url = 'index.php';
$nav_active = 'none';
require_once 'includes/header.php';
?>

        <main class="p-5">

            <!-- فرم ثبت ساختمان -->
            <div class="card p-5" style="margin-top: 8px;">
                <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2" style="font-size: 15px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" />
                    </svg>
                    اطلاعات ساختمان
                </h3>
                <p class="text-xs text-gray-500 mb-4">فیلدهای ستاره‌دار اجباری هستند.</p>

                <form method="POST" action="" class="space-y-4" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="create">
                    <div>
                        <label for="name" class="form-label">نام ساختمان *</label>
                        <input type="text" id="name" name="name" required class="form-input"
                               placeholder="مثال: مجتمع رویال فالنیک"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="address" class="form-label">آدرس *</label>
                        <input type="text" id="address" name="address" required class="form-input"
                               placeholder="مثال: تهران، خیابان اصلی، پلاک ۱۲"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="custom_name" class="form-label">نام نمایشی (اختیاری)</label>
                        <input type="text" id="custom_name" name="custom_name" class="form-input"
                               placeholder="مثال: برج آبی"
                               value="<?= htmlspecialchars($_POST['custom_name'] ?? '') ?>">
                    </div>

                    <!-- مشخصات ساختمانی -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="total_units" class="form-label">تعداد واحد</label>
                            <input type="number" id="total_units" name="total_units" min="0" inputmode="numeric" class="form-input" placeholder="مثال: 20"
                                   value="<?= htmlspecialchars($_POST['total_units'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="total_floors" class="form-label">تعداد طبقه</label>
                            <input type="number" id="total_floors" name="total_floors" min="0" inputmode="numeric" class="form-input" placeholder="مثال: 5"
                                   value="<?= htmlspecialchars($_POST['total_floors'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- بلوک‌ها -->
                    <div class="card bg-gray-50 p-4">
                        <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                            <input type="checkbox" id="has_blocks" name="has_blocks" value="1" class="rounded" checked onchange="document.getElementById('blocks_wrap').style.display = this.checked ? 'block' : 'none'">
                            ساختمان بلوک دارد
                        </label>
                        <div id="blocks_wrap" class="mt-3 space-y-2">
                            <div id="blocks_list" class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <input type="text" name="blocks[]" class="form-input flex-1" placeholder="نام بلوک (مثال: بلوک A)">
                                    <button type="button" onclick="this.parentElement.remove()" class="text-red-500 text-lg leading-none px-2">×</button>
                                </div>
                            </div>
                            <button type="button" onclick="addBlockRow()" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                ＋ افزودن بلوک
                            </button>
                        </div>
                    </div>

                    <!-- عکس پیش‌فرض + رنگ -->
                    <div>
                        <label class="form-label">عکس ساختمان</label>
                        <div class="grid grid-cols-4 gap-2">
                            <?php foreach ($images as $key => $src): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="default_image" value="<?= $key ?>" class="hidden peer" <?= $key === 'b1' ? 'checked' : '' ?>>
                                    <img src="<?= htmlspecialchars($src) ?>" alt="<?= $key ?>"
                                         class="rounded-xl border-2 border-transparent peer-checked:border-blue-600 h-16 w-full object-cover">
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex items-center gap-3 mt-3">
                            <label for="theme_color" class="form-label" style="margin:0;">رنگ تم</label>
                            <input type="color" id="theme_color" name="theme_color" class="form-input" style="width: 56px; height: 44px; padding: 4px;" value="#1a73e8">
                        </div>
                    </div>

                    <!-- پارکینگ و مشاعات -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="parking_spots" class="form-label">ظرفیت پارکینگ (خودرو)</label>
                            <input type="number" id="parking_spots" name="parking_spots" min="0" inputmode="numeric" class="form-input" placeholder="مثال: 10" value="<?= htmlspecialchars($_POST['parking_spots'] ?? '0') ?>">
                        </div>
                    </div>
                    <div class="card bg-gray-50 p-4">
                        <label class="form-label">مشاعات (سالن، لابی، پشت‌بام و ...)</label>
                        <div id="ca_list" class="space-y-2">
                            <div class="flex items-center gap-2">
                                <input type="text" name="common_areas[]" class="form-input flex-1" placeholder="نام مشاع (مثال: سالن اجتماعات)">
                                <button type="button" onclick="this.parentElement.remove()" class="text-red-500 text-lg leading-none px-2">×</button>
                            </div>
                        </div>
                        <button type="button" onclick="addCaRow()" class="mt-2 text-xs bg-violet-50 hover:bg-violet-100 text-violet-700 font-bold px-3 py-2 rounded-lg transition-colors">
                            ＋ افزودن مشاع
                        </button>
                        <p class="text-[11px] text-gray-400 mt-2">مشاعات دلخواه خود را اضافه کنید؛ بعداً هم قابل ویرایش است.</p>
                    </div>

                    <!-- شارژ ثابت ماهیانه -->
                    <div class="card bg-emerald-50 border border-emerald-100 p-4">
                        <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                            <input type="checkbox" name="monthly_charge_enabled" value="1" class="rounded" checked>
                            شارژ ثابت ماهیانه فعال باشد
                        </label>
                        <div class="mt-3">
                            <label for="monthly_charge" class="form-label">مبلغ شارژ ثابت هر ماه (تومان)</label>
                            <input type="number" id="monthly_charge" name="monthly_charge" min="0" step="1000" class="form-input" placeholder="مثال: 500000">
                            <p class="text-[11px] text-gray-500 mt-1">هر ماه به‌صورت خودکار به بدهکاری واحدها اضافه می‌شود.</p>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px;">
                            <path d="M5 13l4 4L19 7" />
                        </svg>
                        ثبت ساختمان
                    </button>
                </form>
            </div>

        </main>

        <script>
            function addBlockRow() {
                var wrap = document.getElementById('blocks_list');
                var div = document.createElement('div');
                div.className = 'flex items-center gap-2';
                div.innerHTML = '<input type="text" name="blocks[]" class="form-input flex-1" placeholder="نام بلوک">'
                    + '<button type="button" onclick="this.parentElement.remove()" class="text-red-500 text-lg leading-none px-2">×</button>';
                wrap.appendChild(div);
            }
            function addCaRow() {
                var wrap = document.getElementById('ca_list');
                var div = document.createElement('div');
                div.className = 'flex items-center gap-2';
                div.innerHTML = '<input type="text" name="common_areas[]" class="form-input flex-1" placeholder="نام مشاع">'
                    + '<button type="button" onclick="this.parentElement.remove()" class="text-red-500 text-lg leading-none px-2">×</button>';
                wrap.appendChild(div);
            }
        </script>

<?php require_once 'includes/footer.php'; ?>
