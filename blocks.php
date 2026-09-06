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
$reopen_modal = '';

// ساختار مجتمع فقط توسط مدیر ساختمان تغییر می‌کند.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند بلوک‌ها را مدیریت کند.';
    } elseif ($action === 'delete') {
        $block_id = (int) ($_POST['block_id'] ?? 0);
        $response = callAPI('DELETE', '/blocks/' . $block_id);
        if (!empty($response['success'])) {
            $alert_message = 'بلوک حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف بلوک.';
        }
    } else {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $alert_message = 'نام بلوک را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-block' : 'add-block';
        } else {
            $payload = [
                'name' => $name,
                'description' => trim($_POST['description'] ?? ''),
            ];
            if ($action === 'update') {
                $block_id = (int) ($_POST['block_id'] ?? 0);
                $response = callAPI('PUT', '/blocks/' . $block_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'بلوک ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش بلوک.';
                    $reopen_modal = 'edit-block';
                }
            } else {
                $response = callAPI('POST', '/buildings/' . $building_id . '/blocks', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'بلوک با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت بلوک.';
                    $reopen_modal = 'add-block';
                }
            }
        }
    }
}

// دریافت اطلاعات ساختمان و لیست بلوک‌ها
$blocks = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (!empty($list_response['success'])) {
        $blocks = $list_response['data']['blocks'] ?? [];
    }
}

$page_title = 'مدیریت بلوک‌ها';
$header_sub = $building_name ?: 'ساختار مجتمع';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-block', 'افزودن بلوک جدید'); ?>
    <?php else: ?>
        <div class="hint-card">🏢 ساختار بلوک‌های مجتمع توسط مدیر ساختمان تعریف می‌شود.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">بلوک‌های ساختمان (<?= fa_digits(count($blocks)) ?>)</h2>
    </div>

    <?php if (empty($blocks)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🏢</div>
            هنوز بلوکی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($blocks as $index => $block): ?>
                <?php
                $bl_id = (int) ($block['id'] ?? 0);
                $bl_name = $block['name'] ?? 'بدون نام';
                $bl_desc = $block['description'] ?? '';
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0 font-bold">
                            <?= fa_digits($index + 1) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($bl_name) ?></h3>
                            <?php if ($bl_desc !== ''): ?>
                                <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($bl_desc) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-block"
                                    data-set-block_id="<?= $bl_id ?>"
                                    data-set-name="<?= htmlspecialchars($bl_name) ?>"
                                    data-set-description="<?= htmlspecialchars($bl_desc) ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="?building_id=<?= $building_id ?>" data-confirm="این بلوک حذف شود؟ طبقات و واحدهای مرتبط بدون بلوک می‌شوند." style="display:inline;">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="block_id" value="<?= $bl_id ?>">
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
    <?php modal_start('add-block', 'ثبت بلوک جدید', 'نام و توضیح بلوک'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="create">
            <div>
                <label class="form-label">نام بلوک *</label>
                <input type="text" name="name" required class="form-input" placeholder="مثال: بلوک A">
            </div>
            <div>
                <label class="form-label">توضیحات (اختیاری)</label>
                <textarea name="description" rows="2" class="form-input" placeholder="مثال: ورودی شمالی"></textarea>
            </div>
            <button type="submit" class="btn-primary">ذخیره بلوک</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-block', 'ویرایش بلوک', 'اصلاح نام یا توضیح'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="block_id" value="">
            <div>
                <label class="form-label">نام بلوک *</label>
                <input type="text" name="name" required class="form-input">
            </div>
            <div>
                <label class="form-label">توضیحات</label>
                <textarea name="description" rows="2" class="form-input"></textarea>
            </div>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
