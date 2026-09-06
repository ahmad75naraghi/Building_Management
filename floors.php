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

// ساختار مجتمع فقط توسط مدیر ساختمان تغییر می‌کند.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// دریافت لیست بلوک‌ها (برای انتخاب در فرم)
$blocks = [];
if ($building_id > 0) {
    $blocks_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (!empty($blocks_response['success'])) {
        $blocks = $blocks_response['data']['blocks'] ?? [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند طبقات را مدیریت کند.';
    } elseif ($action === 'delete') {
        $floor_id = (int) ($_POST['floor_id'] ?? 0);
        $response = callAPI('DELETE', '/floors/' . $floor_id);
        if (!empty($response['success'])) {
            $alert_message = 'طبقه حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف طبقه.';
        }
    } else {
        $floor_number = trim(en_digits($_POST['floor_number'] ?? ''));
        if ($floor_number === '') {
            $alert_message = 'شماره طبقه را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-floor' : 'add-floor';
        } else {
            $payload = [
                'floor_number' => $floor_number,
                'name' => trim($_POST['name'] ?? ''),
                'block_id' => !empty($_POST['block_id']) ? (int) $_POST['block_id'] : null,
            ];
            if ($action === 'update') {
                $floor_id = (int) ($_POST['floor_id'] ?? 0);
                $response = callAPI('PUT', '/floors/' . $floor_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'طبقه ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش طبقه.';
                    $reopen_modal = 'edit-floor';
                }
            } else {
                $response = callAPI('POST', '/buildings/' . $building_id . '/floors', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'طبقه با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت طبقه.';
                    $reopen_modal = 'add-floor';
                }
            }
        }
    }
}

// دریافت اطلاعات ساختمان و لیست طبقات
$floors = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/floors');
    if (!empty($list_response['success'])) {
        $floors = $list_response['data']['floors'] ?? [];
    }
}

$block_names = [];
foreach ($blocks as $b) {
    $block_names[$b['id']] = $b['name'];
}

$page_title = 'مدیریت طبقات';
$header_sub = $building_name ?: 'ساختار مجتمع';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-floor', 'افزودن طبقه جدید'); ?>
    <?php else: ?>
        <div class="hint-card">🏗️ ساختار طبقات مجتمع توسط مدیر ساختمان تعریف می‌شود.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">طبقات ساختمان (<?= fa_digits(count($floors)) ?>)</h2>
    </div>

    <?php if (empty($floors)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🏗️</div>
            هنوز طبقه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($floors as $floor): ?>
                <?php
                $fl_id = (int) ($floor['id'] ?? 0);
                $fl_num = $floor['floor_number'] ?? '';
                $fl_name = $floor['name'] ?? '';
                $fl_block = (int) ($floor['block_id'] ?? 0);
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0 font-bold">
                            <?= fa_digits($fl_num !== '' ? $fl_num : '—') ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm">طبقه <?= fa_digits($fl_num !== '' ? $fl_num : '—') ?></h3>
                            <p class="text-xs text-gray-500 mt-0.5 truncate">
                                <?= htmlspecialchars($fl_name) ?>
                                <?php if ($fl_block > 0 && isset($block_names[$fl_block])): ?>
                                    • بلوک <?= htmlspecialchars($block_names[$fl_block]) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-floor"
                                    data-set-floor_id="<?= $fl_id ?>"
                                    data-set-floor_number="<?= htmlspecialchars((string) $fl_num) ?>"
                                    data-set-name="<?= htmlspecialchars($fl_name) ?>"
                                    data-set-block_id="<?= $fl_block ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="?building_id=<?= $building_id ?>" data-confirm="این طبقه حذف شود؟" style="display:inline;">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="floor_id" value="<?= $fl_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>
    <?php modal_start('add-floor', 'ثبت طبقه جدید', 'شماره، نام و بلوک طبقه'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_floor_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره طبقه</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-floor', 'ویرایش طبقه', 'اصلاح مشخصات طبقه'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="floor_id" value="">
            <?php include 'includes/_floor_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
