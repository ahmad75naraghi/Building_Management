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

// اسناد ساختمان فقط توسط مدیر ثبت/ویرایش/حذف می‌شوند؛ بقیه فقط مشاهده و دانلود.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند اسناد را مدیریت کند.';
    } elseif ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $response = callAPI('DELETE', '/documents/' . $item_id);
        if (!empty($response['success'])) {
            $alert_message = 'سند حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف سند.';
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $file_path = trim($_POST['file_path'] ?? '');
        if ($title === '' || $file_path === '') {
            $alert_message = 'عنوان و آدرس فایل الزامی است.';
            $reopen_modal = $action === 'update' ? 'edit-document' : 'add-document';
        } else {
            $payload = [
                'title' => $title,
                'file_path' => $file_path,
                'document_type' => trim($_POST['document_type'] ?? ''),
            ];
            if ($action === 'update') {
                $item_id = (int) ($_POST['item_id'] ?? 0);
                $response = callAPI('PUT', '/documents/' . $item_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'سند ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش سند.';
                    $reopen_modal = 'edit-document';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/documents', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'سند با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت سند.';
                    $reopen_modal = 'add-document';
                }
            }
        }
    }
}

// دریافت لیست اسناد
$documents = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/documents', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $documents = $list_response['data'] ?? [];
    }
}

$page_title = 'اسناد ساختمان';
$header_sub = $building_name ?: 'آرشیو اسناد';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-document', 'افزودن سند جدید'); ?>
    <?php else: ?>
        <div class="hint-card">📄 اسناد ساختمان توسط مدیر بارگذاری می‌شوند؛ شما می‌توانید آن‌ها را مشاهده و دانلود کنید.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">اسناد و مدارک (<?= fa_digits(count($documents)) ?>)</h2>
    </div>

    <?php if (empty($documents)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">📄</div>
            سندی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($documents as $document): ?>
                <?php
                $d_id = (int) ($document['id'] ?? 0);
                $d_title = $document['title'] ?? 'بدون عنوان';
                $d_type = $document['document_type'] ?? '';
                $d_path = $document['file_path'] ?? '';
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0" style="font-size:20px;">📄</div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($d_title) ?></h3>
                            <?php if ($d_type !== ''): ?>
                                <span class="chip chip-gray" style="margin-top:5px;"><?= htmlspecialchars($d_type) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($d_path !== ''): ?>
                            <a href="<?= htmlspecialchars($d_path) ?>" target="_blank" rel="noopener" class="btn-chip btn-chip-neutral flex-shrink-0">دانلود</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-document"
                                    data-set-item_id="<?= $d_id ?>"
                                    data-set-title="<?= htmlspecialchars($d_title) ?>"
                                    data-set-document_type="<?= htmlspecialchars($d_type) ?>"
                                    data-set-file_path="<?= htmlspecialchars($d_path) ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این سند حذف شود؟" style="display:inline;">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= $d_id ?>">
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
    <?php modal_start('add-document', 'افزودن سند جدید', 'عنوان، نوع و لینک فایل'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_document_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت سند</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-document', 'ویرایش سند', 'اصلاح اطلاعات سند'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="item_id" value="">
            <?php include 'includes/_document_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
