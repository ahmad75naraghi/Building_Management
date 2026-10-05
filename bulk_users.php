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
$bulk_results = null; // نتایج ثبت گروهی برای نمایش در جدول پایین صفحه

// نقش کاربر — ساخت گروهی فقط برای مدیر ساختمان
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// واحدهای ساختمان برای انتخاب در جدول
$units = [];
if ($building_id > 0) {
    $units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (!empty($units_response['success'])) {
        $units = $units_response['data']['units'] ?? [];
    }
}

// ثبت گروهی کاربران
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند کاربر گروهی بسازد.';
    } else {
        $names = $_POST['row_name'] ?? [];
        $phones = $_POST['row_phone'] ?? [];
        $unit_numbers = $_POST['row_unit'] ?? [];
        $roles = $_POST['row_role'] ?? [];
        $passwords = $_POST['row_password'] ?? [];
        $default_password = trim((string) ($_POST['default_password'] ?? ''));
        $force = !empty($_POST['force_replace']);

        $rows = [];
        foreach (array_keys($names) as $i) {
            $name = trim((string) ($names[$i] ?? ''));
            $phone = trim((string) ($phones[$i] ?? ''));
            if ($name === '' && $phone === '') {
                continue; // ردیف کاملاً خالی نادیده گرفته می‌شود
            }
            $rows[] = [
                'name' => $name,
                'phone' => $phone,
                'unit_number' => (string) ($unit_numbers[$i] ?? ''),
                'role' => (string) ($roles[$i] ?? 'resident'),
                'password' => trim((string) ($passwords[$i] ?? '')),
            ];
        }

        if (empty($rows)) {
            $alert_message = 'حداقل یک ردیف با نام و شماره موبایل وارد کنید.';
        } else {
            $response = callAPI('POST', '/buildings/' . $building_id . '/bulk-users', [
                'rows' => $rows,
                'force' => $force,
                'default_password' => $default_password,
            ]);
            if (!empty($response['success'])) {
                $bulk_results = $response['data'] ?? null;
                $alert_message = $response['message'] ?? 'ثبت گروهی انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'ثبت گروهی ناموفق بود.';
                $bulk_results = $response['data'] ?? null;
            }
        }
    }
}

$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<div class="px-4 pt-4 pb-6" style="min-height: 60vh;">

    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-lg font-black text-gray-800">➕ ساخت گروهی کاربران</h1>
            <p class="text-[11px] text-gray-400 mt-1">افزودن سریع ساکنین و اتصال آن‌ها به واحدها در یک جدول</p>
        </div>
        <a href="members.php?building_id=<?= $building_id ?>" class="btn-view-profile" style="font-size:11px; text-decoration:none;">👥 اعضا</a>
    </div>

    <?php if (!$is_manager): ?>
        <div class="bg-white rounded-2xl p-6 text-center text-gray-500 text-sm shadow-sm">
            🔒 ساخت گروهی کاربران فقط برای مدیر ساختمان در دسترس است.
        </div>
    <?php else: ?>

    <?php if ($bulk_results && !empty($bulk_results['results'])): ?>
        <!-- نتیجهٔ ثبت گروهی -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-5">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <span class="text-sm font-extrabold text-gray-800">نتیجهٔ ثبت گروهی</span>
                <?php $s = $bulk_results['summary'] ?? []; ?>
                <span class="text-[11px] text-gray-500">
                    ✅ <?= fa_digits($s['created'] ?? 0) ?> ساخته شد
                    • 🔗 <?= fa_digits($s['linked'] ?? 0) ?> متصل شد
                    • ⏭️ <?= fa_digits($s['skipped'] ?? 0) ?> رد شد
                    • ❌ <?= fa_digits($s['failed'] ?? 0) ?> خطا
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12px]">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500">
                            <th class="px-3 py-2 text-right font-bold">ردیف</th>
                            <th class="px-3 py-2 text-right font-bold">نام</th>
                            <th class="px-3 py-2 text-right font-bold">موبایل</th>
                            <th class="px-3 py-2 text-right font-bold">واحد / نقش</th>
                            <th class="px-3 py-2 text-right font-bold">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bulk_results['results'] as $r): ?>
                        <?php
                        $chip = match ($r['status']) {
                            'created' => ['✅ ساخته شد', 'bg-emerald-50 text-emerald-600'],
                            'linked' => ['🔗 متصل شد', 'bg-blue-50 text-blue-600'],
                            'skipped' => ['⏭️ رد شد', 'bg-amber-50 text-amber-600'],
                            default => ['❌ خطا', 'bg-red-50 text-red-600'],
                        };
                        $role_label = ['owner' => 'مالک', 'tenant' => 'مستاجر', 'resident' => 'ساکن'][$r['role']] ?? $r['role'];
                        ?>
                        <tr class="border-t border-gray-50">
                            <td class="px-3 py-2 text-gray-400"><?= fa_digits($r['row']) ?></td>
                            <td class="px-3 py-2 font-bold text-gray-800"><?= htmlspecialchars($r['name']) ?></td>
                            <td class="px-3 py-2 text-gray-600" dir="ltr"><?= fa_digits($r['phone']) ?></td>
                            <td class="px-3 py-2 text-gray-600">
                                <?= $r['unit'] !== null && $r['unit'] !== '' ? 'واحد ' . htmlspecialchars($r['unit']) : '—' ?>
                                <span class="text-gray-400">(<?= htmlspecialchars($role_label) ?>)</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="chip <?= $chip[1] ?>" style="white-space:normal; text-align:right; height:auto; padding:4px 8px;"><?= $chip[0] ?></span>
                                <?php if (!empty($r['message'])): ?>
                                    <div class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars($r['message']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="bulk_users.php?building_id=<?= $building_id ?>" onsubmit="return bulkBeforeSubmit();">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="bulk_create">

        <!-- تنظیمات کلی -->
        <div class="bg-white rounded-2xl shadow-sm p-4 mb-4 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm font-extrabold text-gray-800">⚙️ تنظیمات</span>
                <div class="flex gap-2">
                    <button type="button" class="btn-view-profile" style="font-size:11px;" onclick="bulkPasteFromClipboard()">📋 چسباندن از اکسل</button>
                    <button type="button" class="btn-view-profile" style="font-size:11px;" onclick="bulkSample()">🧪 داده نمونه</button>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">رمز عبور پیش‌فرض (اختیاری — برای ردیف‌های بدون رمز)</label>
                    <div class="flex gap-2">
                        <input type="text" name="default_password" id="bulk-default-pass" dir="ltr" class="input-field flex-1" placeholder="خالی = ورود با کد یک‌بارمصرف" autocomplete="new-password">
                        <button type="button" class="btn-view-profile" style="font-size:11px;" onclick="bulkGenPass()">🎲 تولید رمز</button>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-[12px] text-gray-600 cursor-pointer">
                    <input type="checkbox" name="force_replace" value="1" class="w-4 h-4 accent-[#d4a373]">
                    جایگزینی ساکن فعلی در واحدهای پُر (در صورت خالی‌بودن تیک، واحد پُر رد می‌شود)
                </label>
            </div>
            <p class="text-[10px] text-gray-400 leading-5">
                💡 از اکسل یا گوگل‌شیت، ستون‌های «نام، موبایل، واحد، نقش» را انتخاب و همین‌جا بچسبانید — ردیف‌ها خودکار پر می‌شوند.
                نقش‌ها: مالک / مستاجر / ساکن.
            </p>
        </div>

        <!-- جدول ردیف‌ها -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-4">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <span class="text-sm font-extrabold text-gray-800">👤 ردیف‌های کاربران <span id="bulk-count" class="text-[10px] text-gray-400 font-normal"></span></span>
                <div class="flex gap-2">
                    <button type="button" class="btn-view-profile" style="font-size:11px;" onclick="bulkAddRow()">+ ردیف جدید</button>
                    <button type="button" class="btn-view-profile" style="font-size:11px;" onclick="bulkClearRows()">🗑️ پاک‌کردن همه</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12px]" id="bulk-table">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500">
                            <th class="px-2 py-2 w-8 text-center font-bold">#</th>
                            <th class="px-2 py-2 text-right font-bold min-w-[130px]">نام و نام خانوادگی</th>
                            <th class="px-2 py-2 text-right font-bold min-w-[120px]">موبایل</th>
                            <th class="px-2 py-2 text-right font-bold min-w-[100px]">واحد</th>
                            <th class="px-2 py-2 text-right font-bold min-w-[90px]">نقش</th>
                            <th class="px-2 py-2 text-right font-bold min-w-[110px]">رمز (اختیاری)</th>
                            <th class="px-2 py-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody id="bulk-rows"></tbody>
                </table>
            </div>
        </div>

        <button type="submit" class="btn-gold w-full" style="padding:14px; font-size:14px;">
            ✅ ثبت گروهی کاربران
        </button>
    </form>
    <?php endif; ?>
</div>

<script>
// داده‌های سرور برای پرکردن جدول
const BULK_UNITS = <?= json_encode(array_map(fn($u) => [
    'n' => (string) $u['unit_number'],
    'busy' => !empty($u['owner_name']) || !empty($u['tenant_name']),
    'who' => trim(($u['owner_name'] ?? '') . (!empty($u['owner_name']) && !empty($u['tenant_name']) ? '، ' : '') . ($u['tenant_name'] ?? '')),
], $units), JSON_UNESCAPED_UNICODE) ?>;

let bulkRowSeq = 0;

function bulkAddRow(data = {}) {
    bulkRowSeq++;
    const tbody = document.getElementById('bulk-rows');
    const tr = document.createElement('tr');
    tr.className = 'border-t border-gray-50 bulk-row';
    const unitOptions = BULK_UNITS.map(u =>
        '<option value="' + u.n.replace(/"/g, '&quot;') + '"' +
        (data.unit === u.n ? ' selected' : '') + '>' +
        u.n + (u.busy ? ' (' + u.who + ')' : '') + '</option>'
    ).join('');
    const role = data.role || 'owner';
    tr.innerHTML =
        '<td class="px-2 py-2 text-center text-gray-400 bulk-num"></td>' +
        '<td class="px-2 py-1.5"><input type="text" name="row_name[]" class="input-field w-full" placeholder="مثلاً علی محمدی" value="' + (data.name ? String(data.name).replace(/"/g, '&quot;') : '') + '"></td>' +
        '<td class="px-2 py-1.5"><input type="tel" name="row_phone[]" class="input-field w-full" dir="ltr" placeholder="09xxxxxxxxx" value="' + (data.phone ? String(data.phone).replace(/"/g, '&quot;') : '') + '"></td>' +
        '<td class="px-2 py-1.5"><select name="row_unit[]" class="input-field w-full"><option value="">— بدون واحد —</option>' + unitOptions + '</select></td>' +
        '<td class="px-2 py-1.5"><select name="row_role[]" class="input-field w-full">' +
            '<option value="owner"' + (role === 'owner' ? ' selected' : '') + '>مالک</option>' +
            '<option value="tenant"' + (role === 'tenant' ? ' selected' : '') + '>مستاجر</option>' +
            '<option value="resident"' + (role === 'resident' ? ' selected' : '') + '>ساکن</option>' +
        '</select></td>' +
        '<td class="px-2 py-1.5"><input type="text" name="row_password[]" class="input-field w-full" dir="ltr" placeholder="خالی = کد یک‌بارمصرف" value="' + (data.password ? String(data.password).replace(/"/g, '&quot;') : '') + '"></td>' +
        '<td class="px-2 py-1.5 text-center"><button type="button" onclick="bulkRemoveRow(this)" class="text-red-400 hover:text-red-600 text-base leading-none" title="حذف ردیف">✕</button></td>';
    tbody.appendChild(tr);
    bulkRenumber();
}

function bulkRemoveRow(btn) {
    btn.closest('tr').remove();
    bulkRenumber();
}

function bulkRenumber() {
    document.querySelectorAll('#bulk-rows .bulk-row').forEach((tr, i) => {
        tr.querySelector('.bulk-num').textContent = faDigitsLocal(i + 1);
    });
    const n = document.querySelectorAll('#bulk-rows .bulk-row').length;
    document.getElementById('bulk-count').textContent = '— ' + faDigitsLocal(n) + ' ردیف';
}

function faDigitsLocal(v) {
    return String(v).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

function bulkClearRows() {
    if (!confirm('همهٔ ردیف‌ها پاک شوند؟')) return;
    document.getElementById('bulk-rows').innerHTML = '';
    bulkAddRow(); bulkAddRow(); bulkAddRow();
}

// چسباندن از اکسل: ستون‌ها → نام، موبایل، واحد، نقش(، رمز)
function bulkParseText(text) {
    const rows = [];
    text.split(/\r?\n/).forEach(line => {
        const t = line.trim();
        if (!t) return;
        const cells = t.split(/\t|,,|;;|\|/).map(c => c.trim()).filter((c, i, arr) => !(i === arr.length - 1 && c === ''));
        if (cells.length < 2) {
            // شاید جداکننده ویرگول فارسی/انگلیسی باشد
            const alt = t.split(/[,،؛;]/).map(c => c.trim());
            if (alt.length >= 2) return void rows.push(alt);
            rows.push(cells);
        } else {
            rows.push(cells);
        }
    });
    return rows;
}

function bulkRoleFromText(t) {
    t = (t || '').trim();
    if (['مالک', 'صاحب', 'owner'].includes(t)) return 'owner';
    if (['مستاجر', 'مستأجر', 'اجاره‌نشین', 'tenant'].includes(t)) return 'tenant';
    if (['ساکن', 'resident'].includes(t)) return 'resident';
    return 'owner';
}

function bulkPasteFromClipboard() {
    navigator.clipboard.readText().then(text => {
        if (!text || !text.trim()) { alert('کلیپ‌بورد خالی است؛ ابتدا ستون‌ها را از اکسل کپی کنید.'); return; }
        bulkApplyParsed(bulkParseText(text));
    }).catch(() => {
        const text = prompt('متن کپی‌شده از اکسل را اینجا بچسبانید:');
        if (text) bulkApplyParsed(bulkParseText(text));
    });
}

function bulkApplyParsed(cellsRows) {
    document.getElementById('bulk-rows').innerHTML = '';
    let count = 0;
    cellsRows.forEach(cells => {
        if (!cells[0] && !cells[1]) return;
        bulkAddRow({
            name: cells[0] || '',
            phone: (cells[1] || '')
                .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
                .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)),
            unit: (cells[2] || '').trim(),
            role: bulkRoleFromText(cells[3] || ''),
            password: cells[4] || ''
        });
        count++;
    });
    if (count === 0) { alert('هیچ ردیف معتبری پیدا نشد. ترتیب ستون‌ها: نام، موبایل، واحد، نقش، رمز.'); return; }
    alert('✔ ' + count + ' ردیف از کلیپ‌بورد خوانده شد. کنترل کنید و دکمهٔ ثبت را بزنید.');
}

function bulkSample() {
    document.getElementById('bulk-rows').innerHTML = '';
    bulkAddRow({ name: 'علی محمدی', phone: '09121112233', unit: '', role: 'owner' });
    bulkAddRow({ name: 'مریم احمدی', phone: '09122223344', unit: '', role: 'tenant' });
    bulkAddRow({ name: 'رضا کریمی', phone: '09123334455', unit: '', role: 'resident' });
    alert('دادهٔ نمونه بارگذاری شد؛ شماره واحدها را از فهرست هر ردیف انتخاب کنید.');
}

function bulkGenPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    let p = '';
    for (let i = 0; i < 10; i++) p += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('bulk-default-pass').value = p;
}

function bulkBeforeSubmit() {
    const rows = document.querySelectorAll('#bulk-rows .bulk-row');
    let valid = 0;
    rows.forEach(tr => {
        const inputs = tr.querySelectorAll('input, select');
        const name = inputs[0].value.trim();
        const phone = inputs[1].value.trim();
        if (name || phone) valid++;
    });
    if (valid === 0) { alert('حداقل یک ردیف با نام و موبایل وارد کنید.'); return false; }
    return confirm('ثبت ' + valid + ' ردیف انجام شود؟');
}

// شروع با ۳ ردیف خالی
bulkAddRow(); bulkAddRow(); bulkAddRow();
</script>

<?php require_once 'includes/footer.php'; ?>
