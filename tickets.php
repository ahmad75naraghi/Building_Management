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

// ثبت تیکت جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($building_id <= 0) {
        $alert_message = 'ابتدا از صفحه اصلی یک ساختمان انتخاب یا ایجاد کنید.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '' || $description === '') {
            $alert_message = 'عنوان و توضیحات تیکت را وارد کنید.';
            $reopen_modal = 'add-ticket';
        } else {
            $payload = [
                'building_id' => $building_id,
                'title' => $title,
                'description' => $description,
                'category' => $_POST['category'] ?? 'technical',
                'priority' => $_POST['priority'] ?? 'normal',
                'is_anonymous' => isset($_POST['is_anonymous']) ? true : false,
            ];
            $response = callAPI('POST', '/tickets', $payload);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'تیکت با موفقیت ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت تیکت. لطفاً دوباره تلاش کنید.';
                $reopen_modal = 'add-ticket';
            }
        }
    }
}

// دریافت لیست تیکت‌ها
$tickets = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/tickets', ['building_id' => $building_id]);
} else {
    $list_response = callAPI('GET', '/tickets');
}
if (isset($list_response['success']) && $list_response['success'] === true) {
    $tickets = $list_response['data'] ?? [];
}

$category_labels = [
    'technical' => 'فنی',
    'financial' => 'مالی',
    'management' => 'مدیریتی',
    'complaint' => 'شکایت',
    'suggestion' => 'پیشنهاد',
];

$page_title = 'تیکت‌ها';
$header_sub = $building_name ?: 'پشتیبانی و درخواست‌ها';
$back_url = $building_id > 0 ? 'dashboard.php?building_id=' . $building_id : 'index.php';
$nav_active = 'tickets';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php modal_open_button('add-ticket', 'ثبت تیکت جدید'); ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">تیکت‌های من و ساختمان (<?= fa_digits(count($tickets)) ?>)</h2>
    </div>

    <?php if (empty($tickets)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🎫</div>
            تیکتی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($tickets as $ticket): ?>
                <a href="ticket_view.php?id=<?= (int) $ticket['id'] ?>" class="card p-4 block hover:shadow-md transition-all">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($ticket['title'] ?? 'بدون عنوان') ?></h3>
                            <div class="flex items-center gap-2 mt-2 flex-wrap">
                                <span class="text-[10px] px-2 py-0.5 rounded-full <?= htmlspecialchars(ticket_status_color($ticket['status'] ?? '')) ?>">
                                    <?= htmlspecialchars(ticket_status_label($ticket['status'] ?? '')) ?>
                                </span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">
                                    <?= htmlspecialchars($category_labels[$ticket['category'] ?? ''] ?? ($ticket['category'] ?? '')) ?>
                                </span>
                                <?php if (($ticket['priority'] ?? '') === 'urgent'): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-100 text-red-700">فوری</span>
                                <?php elseif (($ticket['priority'] ?? '') === 'high'): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-orange-100 text-orange-700">زیاد</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="text-[11px] text-gray-400 flex-shrink-0 mt-1"><?= fa_time_ago($ticket['created_at'] ?? '') ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php modal_start('add-ticket', 'ثبت تیکت جدید', 'درخواست یا شکایت خود را ثبت کنید'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create">
        <div>
            <label class="form-label">عنوان *</label>
            <input type="text" name="title" required class="form-input" placeholder="مثال: خرابی آسانسور">
        </div>
        <div>
            <label class="form-label">شرح مشکل *</label>
            <textarea name="description" rows="4" required class="form-input" placeholder="توضیح کامل مشکل را بنویسید..."></textarea>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="form-label">دسته‌بندی</label>
                <select name="category" class="form-input">
                    <option value="technical">فنی</option>
                    <option value="financial">مالی</option>
                    <option value="management">مدیریتی</option>
                    <option value="complaint">شکایت</option>
                    <option value="suggestion">پیشنهاد</option>
                </select>
            </div>
            <div>
                <label class="form-label">اولویت</label>
                <select name="priority" class="form-input">
                    <option value="low">کم</option>
                    <option value="normal" selected>عادی</option>
                    <option value="high">زیاد</option>
                    <option value="urgent">فوری</option>
                </select>
            </div>
        </div>
        <label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
            <input type="checkbox" name="is_anonymous" value="1" class="rounded">
            <span class="text-sm font-medium text-gray-700">ثبت ناشناس (نام من نمایش داده نشود)</span>
        </label>
        <button type="submit" class="btn-primary">ثبت تیکت</button>
    </form>
<?php modal_end(); ?>

<?php require_once 'includes/footer.php'; ?>
