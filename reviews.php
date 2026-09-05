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

// ثبت نظر جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $rating = (int) ($_POST['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                $alert_message = 'امتیاز را بین ۱ تا ۵ انتخاب کنید.';
            } else {
                $payload = [
                    'building_id' => $building_id,
                    'rating' => $rating,
                    'category_id' => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
                    'review_text' => trim($_POST['review_text'] ?? ''),
                ];
        $response = callAPI('POST', '/reviews', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'نظر شما با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت نظر.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_name = $_POST['action'];

    if ($action_name === 'delete_review') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/reviews/' . $item_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'حذف با موفقیت انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف.';
            }
        }
    }
}

// دریافت لیست نظرات
$reviews = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/reviews', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $reviews = $list_response['data'] ?? [];
    }
}

// دسته‌بندی‌های نظرات
$review_categories = [];
$categories_response = callAPI('GET', '/review-categories');
if (isset($categories_response['success']) && $categories_response['success'] === true) {
    $review_categories = $categories_response['data'] ?? [];
}
$category_labels = [];
foreach ($review_categories as $cat) {
    $category_labels[$cat['id']] = $cat['name'];
}

$page_title = 'نظرات و امتیازها';
$header_sub = $building_name ?: 'بازخورد ساکنین';
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- دکمه افزودن -->
    <a href="#add-form"
       class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>ثبت نظر جدید</span>
    </a>

    <!-- لیست نظرات -->
    <h2 class="section-title">نظرات ساکنین</h2>

    <?php if (empty($reviews)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">⭐</div>
            نظری ثبت نشده است.<br>
            اولین نظر را شما ثبت کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($reviews as $review): ?>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-amber-400 text-lg tracking-wider" dir="ltr"><?= review_stars($review['rating'] ?? 0) ?></span>
                        <span class="text-[11px] text-gray-400"><?= fa_time_ago($review['created_at'] ?? '') ?></span>
                    </div>
                    <?php if (!empty($review['category_id']) && isset($category_labels[$review['category_id']])): ?>
                        <span class="inline-block mb-2 text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">
                            <?= htmlspecialchars($category_labels[$review['category_id']]) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($review['review_text'])): ?>
                        <p class="text-sm text-gray-600 leading-6"><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-400">(بدون متن)</p>
                    <?php endif; ?>
                    <form method="POST" action="" class="mt-3 pt-3 border-t border-gray-100" data-confirm="این نظر حذف شود؟">
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="item_id" value="<?= (int) $review['id'] ?>">
                        <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors">
                            حذف نظر
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
            </svg>
            ثبت نظر جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="rating" class="form-label">امتیاز *</label>
                <div class="flex gap-2">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?> class="sr-only peer">
                            <span class="text-2xl text-gray-300 peer-checked:text-amber-400 transition-colors">★</span>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>
            <div>
                <label for="category_id" class="form-label">دسته‌بندی (اختیاری)</label>
                <select id="category_id" name="category_id" class="form-input">
                    <option value="">— بدون دسته‌بندی —</option>
                    <?php foreach ($review_categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="review_text" class="form-label">متن نظر</label>
                <textarea id="review_text" name="review_text" rows="3" class="form-input" placeholder="نظر خود را درباره مدیریت ساختمان بنویسید..."></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت نظر
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
