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

// مشاعات فقط توسط مدیر ساختمان تعریف می‌شوند؛ ساکنین آن‌ها را می‌بینند و رزرو می‌کنند.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند مشاعات را مدیریت کند.';
    } elseif ($action === 'delete') {
        $area_id = (int) ($_POST['area_id'] ?? 0);
        $response = callAPI('DELETE', '/common-areas/' . $area_id);
        if (!empty($response['success'])) {
            $alert_message = 'مشاع حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف مشاع.';
        }
    } else {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $alert_message = 'نام مشاع را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-area' : 'add-area';
        } else {
            $payload = [
                'name' => $name,
                'type' => trim($_POST['type'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'bookable' => isset($_POST['bookable']) ? 1 : 0,
            ];
            if ($action === 'update') {
                $area_id = (int) ($_POST['area_id'] ?? 0);
                $response = callAPI('PUT', '/common-areas/' . $area_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'مشاع ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش مشاع.';
                    $reopen_modal = 'edit-area';
                }
            } else {
                $response = callAPI('POST', '/buildings/' . $building_id . '/common-areas', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'مشاع با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت مشاع.';
                    $reopen_modal = 'add-area';
                }
            }
        }
    }
}

// دریافت اطلاعات ساختمان و لیست مشاعات
$common_areas = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/common-areas');
    if (!empty($list_response['success'])) {
        $common_areas = $list_response['data']['common_areas'] ?? [];
    }
}

$page_title = 'مدیریت مشاعات';
$header_sub = $building_name ?: 'ساختار مجتمع';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-area', 'افزودن مشاع جدید'); ?>
    <?php else: ?>
        <div class="hint-card">🎯 مشاعات ساختمان توسط مدیر تعریف می‌شود؛ فضاهای «قابل رزرو» را می‌توانید از صفحه «رزرو مشاعات» رزرو کنید.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">مشاعات ساختمان (<?= fa_digits(count($common_areas)) ?>)</h2>
    </div>

    <?php if (empty($common_areas)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🎯</div>
            هنوز مشاعی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($common_areas as $area): ?>
                <?php
                $a_id = (int) ($area['id'] ?? 0);
                $a_name = $area['name'] ?? 'بدون نام';
                $a_type = $area['type'] ?? '';
                $a_desc = $area['description'] ?? '';
                $a_bookable = (int) ($area['bookable'] ?? 0) === 1;
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0" style="font-size:19px;">🎯</div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($a_name) ?></h3>
                            <?php if ($a_desc !== ''): ?>
                                <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($a_desc) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="building-list-chips" style="margin-top:10px;">
                        <?php if ($a_type !== ''): ?>
                            <span class="chip chip-gray"><?= htmlspecialchars($a_type) ?></span>
                        <?php endif; ?>
                        <?php if ($a_bookable): ?>
                            <span class="chip chip-green">قابل رزرو</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-area"
                                    data-set-area_id="<?= $a_id ?>"
                                    data-set-name="<?= htmlspecialchars($a_name) ?>"
                                    data-set-type="<?= htmlspecialchars($a_type) ?>"
                                    data-set-description="<?= htmlspecialchars($a_desc) ?>"
                                    data-set-bookable="<?= $a_bookable ? '1' : '0' ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="?building_id=<?= $building_id ?>" data-confirm="این مشاع حذف شود؟ رزروهای مرتبط نیز بی‌اعتبار می‌شوند." style="display:inline;">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="area_id" value="<?= $a_id ?>">
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
    <?php modal_start('add-area', 'ثبت مشاع جدید', 'نام، نوع و قابلیت رزرو'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_area_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره مشاع</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-area', 'ویرایش مشاع', 'اصلاح مشخصات فضای مشاع'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="area_id" value="">
            <?php include 'includes/_area_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
