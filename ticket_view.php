<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$ticket_id = (int) ($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    header("Location: tickets.php");
    exit;
}

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// افزودن کامنت / ویرایش / حذف تیکت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    if ($_POST['form_action'] === 'edit_ticket') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '' || $description === '') {
            $alert_message = 'عنوان و شرح تیکت نمی‌تواند خالی باشد.';
            $reopen_modal = 'edit-ticket';
        } else {
            $response = callAPI('PUT', '/tickets/' . $ticket_id, [
                'title' => $title,
                'description' => $description,
                'category' => $_POST['category'] ?? 'technical',
                'priority' => $_POST['priority'] ?? 'normal',
            ]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'تیکت با موفقیت ویرایش شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ویرایش تیکت.';
                $reopen_modal = 'edit-ticket';
            }
        }
    } elseif ($_POST['form_action'] === 'delete_ticket') {
        $response = callAPI('DELETE', '/tickets/' . $ticket_id);
        if (isset($response['success']) && $response['success'] === true) {
            header('Location: tickets.php?deleted=1');
            exit;
        }
        $alert_message = $response['message'] ?? 'خطا در حذف تیکت.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && !in_array($_POST['form_action'], ['edit_ticket', 'delete_ticket'], true)) {
    if ($_POST['form_action'] === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment === '') {
            $alert_message = 'متن کامنت را وارد کنید.';
        } else {
            $response = callAPI('POST', '/tickets/' . $ticket_id . '/comments', [
                'comment' => $comment,
                'is_internal' => isset($_POST['is_internal']) ? true : false,
            ]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'کامنت با موفقیت ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت کامنت.';
            }
        }
    } elseif ($_POST['form_action'] === 'update_status') {
        $status = $_POST['status'] ?? 'open';
        $response = callAPI('PUT', '/tickets/' . $ticket_id . '/status', ['status' => $status]);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'وضعیت تیکت به‌روزرسانی شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت.';
        }
    }
}

// دریافت اطلاعات تیکت
$ticket_response = callAPI('GET', '/tickets/' . $ticket_id);
$ticket = [];
if (isset($ticket_response['success']) && $ticket_response['success'] === true) {
    $ticket = $ticket_response['data'] ?? [];
} else {
    header("Location: tickets.php");
    exit;
}

// دریافت کامنت‌ها
$comments = [];
$comments_response = callAPI('GET', '/tickets/' . $ticket_id . '/comments');
if (isset($comments_response['success']) && $comments_response['success'] === true) {
    $comments = $comments_response['data'] ?? [];
}

$category_labels = [
    'technical' => 'فنی',
    'financial' => 'مالی',
    'management' => 'مدیریتی',
    'complaint' => 'شکایت',
    'suggestion' => 'پیشنهاد',
];

// سطح دسترسی کاربر جاری برای ویرایش/حذف این تیکت
$role_ctx = building_role_context((int) ($ticket['building_id'] ?? 0));
$is_owner = $role_ctx['user_id'] > 0 && $role_ctx['user_id'] === (int) ($ticket['user_id'] ?? 0);
$is_manager = !empty($role_ctx['is_manager']);
$can_edit = $is_manager || $is_owner;
$can_delete = $is_manager || ($is_owner && in_array($ticket['status'] ?? '', ['open', 'rejected'], true));

$page_title = 'جزئیات تیکت';
$header_sub = $ticket['title'] ?? 'تیکت';
$back_url = 'tickets.php';
$active_nav = 'tickets';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- وضعیت تیکت -->
    <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
                <h2 class="font-bold text-gray-900 text-lg leading-7"><?= htmlspecialchars($ticket['title'] ?? 'بدون عنوان') ?></h2>
                <div class="flex items-center gap-2 mt-3 flex-wrap">
                    <span class="text-xs px-2.5 py-1 rounded-full <?= htmlspecialchars(ticket_status_color($ticket['status'] ?? '')) ?>">
                        <?= htmlspecialchars(ticket_status_label($ticket['status'] ?? '')) ?>
                    </span>
                    <span class="text-xs px-2.5 py-1 rounded-full bg-gray-100 text-gray-600">
                        <?= htmlspecialchars($category_labels[$ticket['category'] ?? ''] ?? ($ticket['category'] ?? '')) ?>
                    </span>
                    <span class="text-xs px-2.5 py-1 rounded-full bg-gray-100 text-gray-600">
                        اولویت: <?= htmlspecialchars(ticket_priority_label($ticket['priority'] ?? '')) ?>
                    </span>
                </div>
            </div>
            <span class="text-xs text-gray-400 flex-shrink-0"><?= fa_time_ago($ticket['created_at'] ?? '') ?></span>
        </div>

        <!-- تاریخ ثبت (شمسی) + عملیات -->
        <div class="flex items-center justify-between mt-3 flex-wrap gap-2">
            <span class="text-[11px] text-gray-400">
                ثبت: <?= !empty($ticket['created_at']) ? fa_digits(jdate('l j F Y', strtotime((string) $ticket['created_at']))) : '—' ?>
            </span>
            <div class="flex items-center gap-2">
                <?php if ($can_edit): ?>
                    <button type="button" data-modal-open="edit-ticket"
                            class="text-xs font-bold px-3 py-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors">
                        ✏️ ویرایش
                    </button>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                    <button type="button" data-modal-open="delete-ticket"
                            class="text-xs font-bold px-3 py-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 transition-colors">
                        🗑 حذف
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- شرح تیکت -->
        <div class="mt-4 bg-gray-50 rounded-xl p-4 text-sm text-gray-700 leading-7">
            <?= nl2br(htmlspecialchars($ticket['description'] ?? 'بدون توضیحات')) ?>
        </div>
    </div>

    <!-- تغییر وضعیت -->
    <div class="card p-4 mt-4">
        <h3 class="text-sm font-bold text-gray-700 mb-3">تغییر وضعیت تیکت</h3>
        <form method="POST" action="" class="flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_status">
            <select name="status" class="form-input flex-1">
                <option value="open" <?= ($ticket['status'] ?? '') === 'open' ? 'selected' : '' ?>>باز</option>
                <option value="in_progress" <?= ($ticket['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>در حال بررسی</option>
                <option value="resolved" <?= ($ticket['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>حل‌شده</option>
                <option value="closed" <?= ($ticket['status'] ?? '') === 'closed' ? 'selected' : '' ?>>بسته‌شده</option>
                <option value="rejected" <?= ($ticket['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>ردشده</option>
            </select>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-4 rounded-xl transition-all flex-shrink-0">ثبت</button>
        </form>
    </div>

    <!-- گفتگو / کامنت‌ها -->
    <h2 class="section-title">گفتگو (<?= fa_digits(count($comments)) ?> کامنت)</h2>

    <?php if (empty($comments)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">💬</div>
            هنوز کامنتی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($comments as $comment): ?>
                <div class="card p-4 <?= !empty($comment['is_internal']) ? 'bg-amber-50/60' : '' ?>">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-sm font-bold">
                                <?= htmlspecialchars(mb_substr($comment['user_name'] ?? 'کاربر', 0, 1, 'UTF-8')) ?>
                            </div>
                            <span class="text-sm font-bold text-gray-700"><?= htmlspecialchars($comment['user_name'] ?? 'کاربر') ?></span>
                            <?php if (!empty($comment['is_internal'])): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">داخلی</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-[11px] text-gray-400"><?= fa_time_ago($comment['created_at'] ?? '') ?></span>
                    </div>
                    <p class="text-sm text-gray-600 leading-6"><?= nl2br(htmlspecialchars($comment['comment'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم ثبت کامنت -->
    <div class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4">افزودن کامنت</h3>
        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_comment">
            <div>
                <textarea id="comment" name="comment" rows="3" required class="form-input" placeholder="پاسخ یا پیگیری خود را بنویسید..."></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer bg-gray-50 border border-gray-200 rounded-xl p-3.5">
                <input type="checkbox" id="is_internal" name="is_internal" value="1" class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <span class="text-sm font-medium text-gray-700">کامنت داخلی (فقط برای مدیران)</span>
            </label>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
                ارسال کامنت
            </button>
        </form>
    </div>

</main>

<?php if ($can_edit): ?>
<?php modal_start('edit-ticket', 'ویرایش تیکت', 'عنوان، شرح، دسته‌بندی و اولویت را تغییر دهید'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="edit_ticket">
        <div>
            <label class="form-label">عنوان *</label>
            <input type="text" name="title" required class="form-input"
                   value="<?= htmlspecialchars($ticket['title'] ?? '') ?>">
        </div>
        <div>
            <label class="form-label">شرح مشکل *</label>
            <textarea name="description" rows="4" required class="form-input"><?= htmlspecialchars($ticket['description'] ?? '') ?></textarea>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="form-label">دسته‌بندی</label>
                <select name="category" class="form-input">
                    <?php foreach ($category_labels as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($ticket['category'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">اولویت</label>
                <select name="priority" class="form-input">
                    <option value="low" <?= ($ticket['priority'] ?? '') === 'low' ? 'selected' : '' ?>>کم</option>
                    <option value="normal" <?= ($ticket['priority'] ?? '') === 'normal' ? 'selected' : '' ?>>عادی</option>
                    <option value="high" <?= ($ticket['priority'] ?? '') === 'high' ? 'selected' : '' ?>>زیاد</option>
                    <option value="urgent" <?= ($ticket['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>فوری</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn-primary">ذخیره تغییرات</button>
    </form>
<?php modal_end(); ?>
<?php endif; ?>

<?php if ($can_delete): ?>
<?php modal_start('delete-ticket', 'حذف تیکت', 'این عملیات قابل بازگشت نیست'); ?>
    <form method="POST" action="" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="delete_ticket">
        <p class="text-sm text-gray-600 leading-7 mb-4">
            تیکت «<?= htmlspecialchars($ticket['title'] ?? '') ?>» به‌همراه همه کامنت‌هایش برای همیشه حذف می‌شود.
            آیا مطمئن هستید؟
        </p>
        <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-bold py-3 rounded-xl transition-colors">بله، حذف شود</button>
            <button type="button" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-colors" data-modal-close>انصراف</button>
        </div>
    </form>
<?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/page_tail.php'; ?>
