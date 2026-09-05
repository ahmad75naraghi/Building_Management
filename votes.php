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

// مدیریت فرم‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_vote') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $alert_message = 'عنوان رأی‌گیری را وارد کنید.';
        } else {
            // گزینه‌ها: چند فیلد option[]
            $options = [];
            foreach (($_POST['option'] ?? []) as $opt) {
                $opt = trim((string) $opt);
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
            if (count($options) < 2) {
                $alert_message = 'حداقل ۲ گزینه برای رأی‌گیری وارد کنید.';
            } else {
                $payload = [
                    'building_id' => $building_id,
                    'title' => $title,
                    'description' => trim($_POST['description'] ?? ''),
                    'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                    'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    'options' => $options,
                ];
                $response = callAPI('POST', '/votes', $payload);
                if (isset($response['success']) && $response['success'] === true) {
                    $alert_message = 'رأی‌گیری با موفقیت ایجاد شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ایجاد رأی‌گیری.';
                }
            }
        }
    } elseif ($action === 'cast_vote') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $option_id = (int) ($_POST['option_id'] ?? 0);
        if ($vote_id > 0 && $option_id > 0) {
            $response = callAPI('POST', '/votes/' . $vote_id . '/vote', ['option_id' => $option_id]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'رأی شما ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت رأی.';
            }
        }
    } elseif ($action === 'update_status') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $status = ($_POST['status'] ?? 'closed') === 'active' ? 'active' : 'closed';
        if ($vote_id > 0) {
            $response = callAPI('PUT', '/votes/' . $vote_id . '/status', ['status' => $status]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = $status === 'closed' ? 'رأی‌گیری بسته شد.' : 'رأی‌گیری دوباره باز شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت.';
            }
        }
    } elseif ($action === 'delete_vote') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        if ($vote_id > 0) {
            $response = callAPI('DELETE', '/votes/' . $vote_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'رأی‌گیری حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف رأی‌گیری.';
            }
        }
    }
}

// دریافت لیست رأی‌گیری‌ها
$votes = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/votes', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $votes = $list_response['data'] ?? [];
    }
}

$page_title = 'رأی‌گیری‌ها';
$header_sub = $building_name ?: 'نظرسنجی ساکنین';
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
        <span>ایجاد رأی‌گیری جدید</span>
    </a>

    <!-- لیست رأی‌گیری‌ها -->
    <h2 class="section-title">رأی‌گیری‌های ساختمان</h2>

    <?php if (empty($votes)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🗳️</div>
            رأی‌گیری‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($votes as $vote): ?>
                <?php
                $v_status = $vote['status'] ?? 'active';
                $is_active = $v_status === 'active';
                $user_has_voted = !empty($vote['user_has_voted']);
                $options = $vote['options'] ?? [];
                $results = $vote['results'] ?? [];
                $result_options = $results['options'] ?? [];
                $total_votes = (int) ($results['total_votes'] ?? 0);
                $my_option_id = (int) ($vote['my_option_id'] ?? 0);
                $can_vote = $is_active && !$user_has_voted && !empty($options);
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1"><?= htmlspecialchars($vote['title'] ?? 'بدون عنوان') ?></h3>
                        <span class="text-[11px] px-2 py-0.5 rounded-full flex-shrink-0 <?= $is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' ?>">
                            <?= htmlspecialchars(vote_status_label($v_status)) ?>
                        </span>
                    </div>
                    <?php if (!empty($vote['description'])): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($vote['description'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($vote['end_date'])): ?>
                        <p class="text-xs text-gray-400 mt-2">پایان: <?= htmlspecialchars($vote['end_date']) ?></p>
                    <?php endif; ?>

                    <?php if ($can_vote): ?>
                        <!-- فرم رأی دادن -->
                        <form method="POST" action="" class="mt-3 space-y-2">
                            <input type="hidden" name="action" value="cast_vote">
                            <input type="hidden" name="vote_id" value="<?= (int) $vote['id'] ?>">
                            <?php foreach ($options as $opt): ?>
                                <label class="flex items-center gap-3 bg-gray-50 hover:bg-blue-50 border border-gray-100 rounded-xl p-3 cursor-pointer transition-colors">
                                    <input type="radio" name="option_id" value="<?= (int) $opt['id'] ?>" required class="h-4 w-4 text-blue-600">
                                    <span class="text-sm text-gray-700"><?= htmlspecialchars($opt['option_text'] ?? '') ?></span>
                                </label>
                            <?php endforeach; ?>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                                ثبت رأی
                            </button>
                        </form>
                    <?php elseif (!empty($options)): ?>
                        <!-- نمایش نتایج -->
                        <div class="mt-3 space-y-2">
                            <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                                <span><?= $user_has_voted ? 'نتیجه رأی‌گیری' : 'نتایج (پس از رأی شما نمایش داده می‌شود)' ?></span>
                                <span><?= fa_digits($total_votes) ?> رأی</span>
                            </div>
                            <?php foreach ($result_options as $ro): ?>
                                <?php $pct = (float) ($ro['percentage'] ?? 0); ?>
                                <div class="<?= ((int) ($ro['option_id'] ?? 0) === $my_option_id) ? 'bg-blue-50 border border-blue-200' : 'bg-gray-50' ?> rounded-xl p-3">
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span class="text-gray-700 font-medium">
                                            <?= htmlspecialchars($ro['option_text'] ?? '') ?>
                                            <?php if ((int) ($ro['option_id'] ?? 0) === $my_option_id): ?>
                                                <span class="text-[10px] text-blue-600 mr-1">(انتخاب شما)</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-xs text-gray-500"><?= fa_digits($ro['votes_count'] ?? 0) ?> رأی — <?= fa_digits($pct) ?>٪</span>
                                    </div>
                                    <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-blue-500 rounded-full transition-all" style="width: <?= max(0, min(100, (int) round($pct))) ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$user_has_voted && !$is_active): ?>
                                <p class="text-[11px] text-gray-400">این رأی‌گیری بسته شده است.</p>
                            <?php elseif ($user_has_voted && $is_active): ?>
                                <p class="text-[11px] text-gray-400">شما قبلاً رأی داده‌اید.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-xs text-gray-400 mt-3">گزینه‌ای برای این رأی‌گیری ثبت نشده است.</p>
                    <?php endif; ?>

                    <!-- مدیریت -->
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="vote_id" value="<?= (int) $vote['id'] ?>">
                            <input type="hidden" name="status" value="<?= $is_active ? 'closed' : 'active' ?>">
                            <button type="submit" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                <?= $is_active ? 'بستن رأی‌گیری' : 'بازکردن دوباره' ?>
                            </button>
                        </form>
                        <form method="POST" action="" data-confirm="این رأی‌گیری برای همیشه حذف شود؟">
                            <input type="hidden" name="action" value="delete_vote">
                            <input type="hidden" name="vote_id" value="<?= (int) $vote['id'] ?>">
                            <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors">
                                حذف
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            ایجاد رأی‌گیری جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="create_vote">
            <div>
                <label for="title" class="form-label">عنوان رأی‌گیری *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: رنگ‌آمیزی نمای ساختمان">
            </div>
            <div>
                <label for="description" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" rows="2" class="form-input" placeholder="شرح موضوع رأی‌گیری..."></textarea>
            </div>
            <div id="options-container" class="space-y-2">
                <label class="form-label">گزینه‌ها * (حداقل ۲ گزینه)</label>
                <div class="flex items-center gap-2">
                    <input type="text" name="option[]" required class="form-input" placeholder="گزینه ۱">
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" name="option[]" required class="form-input" placeholder="گزینه ۲">
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" name="option[]" class="form-input" placeholder="گزینه ۳ (اختیاری)">
                </div>
            </div>
            <button type="button" id="add-option-btn" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg transition-colors">
                + افزودن گزینه دیگر
            </button>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="start_date" class="form-label">شروع</label>
                    <input type="date" id="start_date" name="start_date" class="form-input">
                </div>
                <div>
                    <label for="end_date" class="form-label">پایان</label>
                    <input type="date" id="end_date" name="end_date" class="form-input">
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ایجاد رأی‌گیری
            </button>
        </form>
    </div>

</main>

<script>
    // افزودن گزینه‌های بیشتر در فرم ایجاد رأی‌گیری
    document.getElementById('add-option-btn').addEventListener('click', function () {
        const container = document.getElementById('options-container');
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center gap-2';
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'option[]';
        input.className = 'form-input';
        input.placeholder = 'گزینه جدید';
        wrapper.appendChild(input);
        container.appendChild(wrapper);
    });
</script>

<?php require_once 'includes/page_tail.php'; ?>
