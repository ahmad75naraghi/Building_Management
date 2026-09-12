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

// نقش کاربر: همه اعضا می‌توانند درخواست ثبت کنند؛
// تغییر وضعیت فقط برای مدیر، ویرایش/حذف برای مدیر یا ثبت‌کننده همان درخواست.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];
$current_user_id = $ctx['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if ($action === 'update_status') {
        if (!$is_manager) {
            $alert_message = 'تغییر وضعیت درخواست فقط توسط مدیر ساختمان انجام می‌شود.';
        } else {
            $request_id = (int) ($_POST['request_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if ($request_id > 0 && in_array($status, ['pending', 'in_progress', 'resolved', 'closed'], true)) {
                $response = callAPI('PUT', '/maintenance/' . $request_id . '/status', ['status' => $status]);
                if (!empty($response['success'])) {
                    $alert_message = 'وضعیت درخواست به‌روزرسانی شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $response = callAPI('DELETE', '/maintenance/' . $request_id);
        if (!empty($response['success'])) {
            $alert_message = 'درخواست حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف درخواست.';
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '') {
            $alert_message = 'عنوان مشکل را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-maintenance' : 'add-maintenance';
        } elseif ($action === 'update') {
            $request_id = (int) ($_POST['request_id'] ?? 0);
            $response = callAPI('PUT', '/maintenance/' . $request_id, [
                'title' => $title,
                'description' => $description,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'درخواست ویرایش شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ویرایش درخواست.';
                $reopen_modal = 'edit-maintenance';
            }
        } else {
            $response = callAPI('POST', '/maintenance', [
                'building_id' => $building_id,
                'title' => $title,
                'description' => $description,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'درخواست تعمیرات با موفقیت ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت درخواست.';
                $reopen_modal = 'add-maintenance';
            }
        }
    }
}

// دریافت لیست درخواست‌ها
$requests = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/maintenance', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $requests = $list_response['data'] ?? [];
    }
}

$status_chips = [
    'pending' => 'chip-amber',
    'in_progress' => 'chip-gold',
    'resolved' => 'chip-green',
    'closed' => 'chip-gray',
];

$page_title = 'درخواست تعمیرات';
$header_sub = $building_name ?: 'پشتیبانی فنی';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php modal_open_button('add-maintenance', 'ثبت درخواست جدید'); ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">درخواست‌های تعمیرات (<?= fa_digits(count($requests)) ?>)</h2>
    </div>

    <?php if (empty($requests)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔧</div>
            درخواست تعمیراتی ثبت نشده است.
            <button type="button" class="empty-action" data-modal-open="add-maintenance">🔧 ثبت اولین درخواست</button>
        </div>
    <?php else: ?>
                <div class="list-filter-bar">
            <input type="search" class="form-input" data-list-search="maintenance-list" placeholder="🔍 جستجوی عنوان یا وضعیت درخواست تعمیرات…" style="flex:1;">
            <span class="list-count-chip" data-list-count="maintenance-list"></span>
        </div>
        <div class="space-y-3" data-list-items="maintenance-list">
            <?php foreach ($requests as $request): ?>
                <?php
                $r_id = (int) ($request['id'] ?? 0);
                $r_title = $request['title'] ?? 'بدون عنوان';
                $r_desc = $request['description'] ?? '';
                $r_status = $request['status'] ?? 'pending';
                // ثبت‌کننده درخواست می‌تواند آن را ویرایش/حذف کند
                $is_mine = (int) ($request['user_id'] ?? 0) === $current_user_id;
                $can_modify = $is_manager || $is_mine;
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1"><?= htmlspecialchars($r_title) ?></h3>
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_time_ago($request['created_at'] ?? '') ?></span>
                    </div>

                    <?php if ($r_desc !== ''): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($r_desc)) ?></p>
                    <?php endif; ?>

                    <div class="building-list-chips" style="margin-top:10px;">
                        <span class="chip <?= $status_chips[$r_status] ?? 'chip-gray' ?>">
                            <?= htmlspecialchars(maintenance_status_label($r_status)) ?>
                        </span>
                        <?php if (!empty($request['user_name'])): ?>
                            <span class="chip chip-gray">ثبت: <?= htmlspecialchars($request['user_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_modify): ?>
                        <div class="card-actions" style="flex-wrap:wrap;">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-maintenance"
                                    data-set-request_id="<?= $r_id ?>"
                                    data-set-title="<?= htmlspecialchars($r_title) ?>"
                                    data-set-description="<?= htmlspecialchars($r_desc) ?>">
                                ویرایش
                            </button>

                            <?php if ($is_manager): ?>
                                <form method="POST" action="" style="display:flex;gap:6px;align-items:center;flex:1;min-width:180px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="update_status">
                                    <input type="hidden" name="request_id" value="<?= $r_id ?>">
                                    <select name="status" class="form-input" style="padding:7px 10px;font-size:11.5px;flex:1;">
                                        <option value="pending" <?= $r_status === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                        <option value="in_progress" <?= $r_status === 'in_progress' ? 'selected' : '' ?>>در حال انجام</option>
                                        <option value="resolved" <?= $r_status === 'resolved' ? 'selected' : '' ?>>انجام‌شده</option>
                                        <option value="closed" <?= $r_status === 'closed' ? 'selected' : '' ?>>بسته‌شده</option>
                                    </select>
                                    <button type="submit" class="btn-chip btn-chip-neutral">ثبت</button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="" data-confirm="این درخواست حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="request_id" value="<?= $r_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div data-list-pager="maintenance-list"></div>
    <?php endif; ?>

</main>

<?php modal_start('add-maintenance', 'ثبت درخواست تعمیرات', 'مشکل را برای مدیر ساختمان گزارش کنید'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create">
        <div>
            <label for="add_m_title" class="form-label">عنوان مشکل *</label>
            <input type="text" id="add_m_title" name="title" required class="form-input" placeholder="مثال: نشتی لوله آب">
        </div>
        <div>
            <label for="add_m_desc" class="form-label">توضیحات (اختیاری)</label>
            <textarea id="add_m_desc" name="description" rows="4" class="form-input" placeholder="جزئیات مشکل و محل دقیق آن..."></textarea>
        </div>
        <button type="submit" class="btn-primary">ثبت درخواست</button>
    </form>
<?php modal_end(); ?>

<?php modal_start('edit-maintenance', 'ویرایش درخواست', 'اصلاح عنوان یا توضیحات'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update">
        <input type="hidden" name="request_id" value="">
        <div>
            <label for="edit_m_title" class="form-label">عنوان مشکل *</label>
            <input type="text" id="edit_m_title" name="title" required class="form-input">
        </div>
        <div>
            <label for="edit_m_desc" class="form-label">توضیحات</label>
            <textarea id="edit_m_desc" name="description" rows="4" class="form-input"></textarea>
        </div>
        <button type="submit" class="btn-primary">ذخیره تغییرات</button>
    </form>
<?php modal_end(); ?>

<?php require_once 'includes/footer.php'; ?>
