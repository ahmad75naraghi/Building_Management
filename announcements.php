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

// نقش کاربر جاری — فقط مدیر می‌تواند اطلاعیه ثبت/ویرایش/حذف کند
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند اطلاعیه‌ها را مدیریت کند.';
    } elseif ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/announcements/' . $item_id);
            if (!empty($response['success'])) {
                $alert_message = 'اطلاعیه حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف اطلاعیه.';
            }
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $is_pinned = isset($_POST['is_pinned']);

        if ($title === '' || $content === '') {
            $alert_message = 'عنوان و متن اطلاعیه را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-announcement' : 'add-announcement';
        } elseif ($action === 'update') {
            $item_id = (int) ($_POST['item_id'] ?? 0);
            $response = callAPI('PUT', '/announcements/' . $item_id, [
                'title' => $title,
                'content' => $content,
                'is_pinned' => $is_pinned,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'اطلاعیه ویرایش شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ویرایش اطلاعیه.';
                $reopen_modal = 'edit-announcement';
            }
        } else {
            $response = callAPI('POST', '/announcements', [
                'building_id' => $building_id,
                'title' => $title,
                'content' => $content,
                'is_pinned' => $is_pinned,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'اطلاعیه با موفقیت ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت اطلاعیه.';
                $reopen_modal = 'add-announcement';
            }
        }
    }
}

// دریافت لیست اطلاعیه‌ها
$announcements = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/announcements', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $announcements = $list_response['data'] ?? [];
    }
}

$page_title = 'اطلاعیه‌ها';
$header_sub = $building_name ?: 'اعلان‌های ساختمان';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-announcement', 'ثبت اطلاعیه جدید'); ?>
    <?php else: ?>
        <div class="hint-card">
            📢 اطلاعیه‌ها توسط مدیر ساختمان منتشر می‌شوند. شما به‌عنوان «<?= htmlspecialchars($ctx['role_label']) ?>» می‌توانید آن‌ها را مطالعه کنید.
        </div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">آخرین اطلاعیه‌ها (<?= fa_digits(count($announcements)) ?>)</h2>
    </div>

    <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <div class="empty-icon">📢</div>
            هنوز اطلاعیه‌ای ثبت نشده است.
            <?php if ($is_manager): ?>
                <button type="button" class="empty-action" data-modal-open="add-announcement">📢 ثبت اولین اطلاعیه</button>
            <?php endif; ?>
        </div>
    <?php else: ?>
                <div class="list-filter-bar">
            <input type="search" class="form-input" data-list-search="announcements-list" placeholder="🔍 جستجوی عنوان یا متن اطلاعیه…" style="flex:1;">
            <span class="list-count-chip" data-list-count="announcements-list"></span>
        </div>
        <div class="space-y-3" data-list-items="announcements-list">
            <?php foreach ($announcements as $announcement): ?>
                <?php
                $a_id = (int) ($announcement['id'] ?? 0);
                $a_title = $announcement['title'] ?? 'بدون عنوان';
                $a_content = $announcement['content'] ?? '';
                $a_pinned = !empty($announcement['is_pinned']);
                ?>
                <div class="card p-4" style="<?= $a_pinned ? 'border-right: 4px solid var(--blue-info);' : '' ?>">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1">
                            <?php if ($a_pinned): ?><span style="color: var(--blue-info);">📌</span><?php endif; ?>
                            <?= htmlspecialchars($a_title) ?>
                        </h3>
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_time_ago($announcement['created_at'] ?? '') ?></span>
                    </div>

                    <?php if ($a_content !== ''): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($a_content)) ?></p>
                    <?php endif; ?>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-announcement"
                                    data-set-item_id="<?= $a_id ?>"
                                    data-set-title="<?= htmlspecialchars($a_title) ?>"
                                    data-set-content="<?= htmlspecialchars($a_content) ?>"
                                    data-set-is_pinned="<?= $a_pinned ? '1' : '0' ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                </svg>
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این اطلاعیه حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= $a_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6" />
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    </svg>
                                    حذف
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div data-list-pager="announcements-list"></div>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>

    <?php modal_start('add-announcement', 'ثبت اطلاعیه جدید', 'برای همه ساکنین ارسال می‌شود'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <div>
                <label for="add_title" class="form-label">عنوان *</label>
                <input type="text" id="add_title" name="title" required class="form-input" placeholder="مثال: قطعی آب">
            </div>
            <div>
                <label for="add_content" class="form-label">متن اطلاعیه *</label>
                <textarea id="add_content" name="content" rows="4" required class="form-input" placeholder="متن کامل اطلاعیه را بنویسید..."></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
                <input type="checkbox" name="is_pinned" value="1" class="rounded">
                <span class="text-sm font-medium text-gray-700">پین شود (نمایش در ابتدای لیست)</span>
            </label>
            <button type="submit" class="btn-primary">ثبت اطلاعیه</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-announcement', 'ویرایش اطلاعیه', 'تغییرات برای همه ساکنین دیده می‌شود'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="item_id" value="">
            <div>
                <label for="edit_title" class="form-label">عنوان *</label>
                <input type="text" id="edit_title" name="title" required class="form-input">
            </div>
            <div>
                <label for="edit_content" class="form-label">متن اطلاعیه *</label>
                <textarea id="edit_content" name="content" rows="4" required class="form-input"></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
                <input type="checkbox" name="is_pinned" value="1" class="rounded">
                <span class="text-sm font-medium text-gray-700">پین شود (نمایش در ابتدای لیست)</span>
            </label>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
