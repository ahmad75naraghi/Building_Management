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

// ثبت تیکت جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($building_id <= 0) {
        $alert_message = 'ابتدا از صفحه اصلی یک ساختمان انتخاب یا ایجاد کنید.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '' || $description === '') {
            $alert_message = 'عنوان و توضیحات تیکت را وارد کنید.';
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
$back_url = $building_id > 0 ? 'building_view.php?id=' . $building_id : 'index.php';
$active_nav = 'tickets';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- دکمه افزودن -->
    <a href="#add-ticket"
       class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>ثبت تیکت جدید</span>
    </a>

    <!-- لیست تیکت‌ها -->
    <h2 class="section-title">تیکت‌های من و ساختمان</h2>

    <?php if (empty($tickets)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🎫</div>
            تیکتی ثبت نشده است.<br>
            اولین تیکت خود را با دکمه بالا ثبت کنید.
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

    <!-- فرم ثبت تیکت -->
    <div id="add-ticket" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
            </svg>
            ثبت تیکت جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="title" class="form-label">عنوان *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: خرابی آسانسور">
            </div>
            <div>
                <label for="description" class="form-label">شرح مشکل *</label>
                <textarea id="description" name="description" rows="3" required class="form-input" placeholder="توضیح کامل مشکل را بنویسید..."></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="category" class="form-label">دسته‌بندی</label>
                    <select id="category" name="category" class="form-input">
                        <option value="technical">فنی</option>
                        <option value="financial">مالی</option>
                        <option value="management">مدیریتی</option>
                        <option value="complaint">شکایت</option>
                        <option value="suggestion">پیشنهاد</option>
                    </select>
                </div>
                <div>
                    <label for="priority" class="form-label">اولویت</label>
                    <select id="priority" name="priority" class="form-input">
                        <option value="low">کم</option>
                        <option value="normal" selected>عادی</option>
                        <option value="high">زیاد</option>
                        <option value="urgent">فوری</option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-3 cursor-pointer bg-gray-50 border border-gray-200 rounded-xl p-3.5">
                <input type="checkbox" id="is_anonymous" name="is_anonymous" value="1" class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <span class="text-sm font-medium text-gray-700">ثبت ناشناس (نام من نمایش داده نشود)</span>
            </label>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت تیکت
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
