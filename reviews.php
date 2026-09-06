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

// هر عضو می‌تواند نظر بدهد؛ ویرایش/حذف فقط نظر خودش (یا مدیر).
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];
$current_user_id = $ctx['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $response = callAPI('DELETE', '/reviews/' . $item_id);
        if (!empty($response['success'])) {
            $alert_message = 'نظر حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف نظر.';
        }
    } else {
        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $alert_message = 'امتیاز را بین ۱ تا ۵ انتخاب کنید.';
            $reopen_modal = $action === 'update' ? 'edit-review' : 'add-review';
        } else {
            $payload = [
                'rating' => $rating,
                'category_id' => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
                'review_text' => trim($_POST['review_text'] ?? ''),
            ];
            if ($action === 'update') {
                $item_id = (int) ($_POST['item_id'] ?? 0);
                $response = callAPI('PUT', '/reviews/' . $item_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'نظر شما ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش نظر.';
                    $reopen_modal = 'edit-review';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/reviews', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'نظر شما با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت نظر.';
                    $reopen_modal = 'add-review';
                }
            }
        }
    }
}

// دریافت لیست نظرات
$reviews = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/reviews', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $reviews = $list_response['data'] ?? [];
    }
}

// دسته‌بندی‌های نظرات
$review_categories = [];
$categories_response = callAPI('GET', '/review-categories');
if (!empty($categories_response['success'])) {
    $review_categories = $categories_response['data'] ?? [];
}
$category_labels = [];
foreach ($review_categories as $cat) {
    $category_labels[$cat['id']] = $cat['name'];
}

// میانگین امتیاز
$avg_rating = 0.0;
if (!empty($reviews)) {
    $sum = array_sum(array_map(static fn($r) => (int) ($r['rating'] ?? 0), $reviews));
    $avg_rating = round($sum / count($reviews), 1);
}

$page_title = 'نظرات و امتیازها';
$header_sub = $building_name ?: 'بازخورد ساکنین';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php modal_open_button('add-review', 'ثبت نظر جدید'); ?>

    <?php if (!empty($reviews)): ?>
        <div class="card p-4" style="margin-top:12px;text-align:center;">
            <p class="text-xs text-gray-500">میانگین رضایت ساکنین</p>
            <p style="font-size:28px;font-weight:800;color:var(--gold-primary);margin-top:4px;"><?= fa_number($avg_rating) ?></p>
            <span class="text-amber-400 text-lg tracking-wider" dir="ltr"><?= review_stars((int) round($avg_rating)) ?></span>
            <p class="text-[11px] text-gray-400 mt-1">از <?= fa_digits(count($reviews)) ?> نظر</p>
        </div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">نظرات ساکنین (<?= fa_digits(count($reviews)) ?>)</h2>
    </div>

    <?php if (empty($reviews)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">⭐</div>
            نظری ثبت نشده است.<br>اولین نظر را شما ثبت کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($reviews as $review): ?>
                <?php
                $rv_id = (int) ($review['id'] ?? 0);
                $rv_rating = (int) ($review['rating'] ?? 0);
                $rv_cat = (int) ($review['category_id'] ?? 0);
                $rv_text = $review['review_text'] ?? '';
                $is_mine = (int) ($review['user_id'] ?? 0) === $current_user_id;
                ?>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-amber-400 text-lg tracking-wider" dir="ltr"><?= review_stars($rv_rating) ?></span>
                        <span class="text-[11px] text-gray-400"><?= fa_time_ago($review['created_at'] ?? '') ?></span>
                    </div>

                    <?php if ($rv_cat > 0 && isset($category_labels[$rv_cat])): ?>
                        <span class="chip chip-gray" style="margin-bottom:8px;"><?= htmlspecialchars($category_labels[$rv_cat]) ?></span>
                    <?php endif; ?>

                    <?php if ($rv_text !== ''): ?>
                        <p class="text-sm text-gray-600 leading-6"><?= nl2br(htmlspecialchars($rv_text)) ?></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-400">(بدون متن)</p>
                    <?php endif; ?>

                    <?php if ($is_mine || $is_manager): ?>
                        <div class="card-actions">
                            <?php if ($is_mine): ?>
                                <button type="button" class="btn-chip btn-chip-edit"
                                        data-modal-open="edit-review"
                                        data-set-item_id="<?= $rv_id ?>"
                                        data-set-rating="<?= $rv_rating ?>"
                                        data-set-category_id="<?= $rv_cat ?>"
                                        data-set-review_text="<?= htmlspecialchars($rv_text) ?>">
                                    ویرایش
                                </button>
                            <?php endif; ?>
                            <form method="POST" action="" data-confirm="این نظر حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= $rv_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php modal_start('add-review', 'ثبت نظر جدید', 'امتیاز و بازخورد شما درباره مدیریت'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create">
        <?php $modal_uid = 'add'; include 'includes/_review_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ثبت نظر</button>
    </form>
<?php modal_end(); ?>

<?php modal_start('edit-review', 'ویرایش نظر', 'اصلاح امتیاز یا متن نظر'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update">
        <input type="hidden" name="item_id" value="">
        <?php $modal_uid = 'edit'; include 'includes/_review_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ذخیره تغییرات</button>
    </form>
<?php modal_end(); ?>

<?php require_once 'includes/footer.php'; ?>
