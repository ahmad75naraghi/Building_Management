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

// ایجاد/ویرایش/حذف رأی‌گیری فقط با مدیر ساختمان؛ رأی دادن برای همه اعضا.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// مدیریت فرم‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'cast_vote') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $option_id = (int) ($_POST['option_id'] ?? 0);
        if ($vote_id > 0 && $option_id > 0) {
            $response = callAPI('POST', '/votes/' . $vote_id . '/vote', ['option_id' => $option_id]);
            if (!empty($response['success'])) {
                $alert_message = 'رأی شما ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت رأی.';
            }
        }
    } elseif (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند رأی‌گیری ایجاد یا ویرایش کند.';
    } elseif ($action === 'update_status') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $status = ($_POST['status'] ?? 'closed') === 'active' ? 'active' : 'closed';
        if ($vote_id > 0) {
            $response = callAPI('PUT', '/votes/' . $vote_id . '/status', ['status' => $status]);
            if (!empty($response['success'])) {
                $alert_message = $status === 'closed' ? 'رأی‌گیری بسته شد.' : 'رأی‌گیری دوباره باز شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت.';
            }
        }
    } elseif ($action === 'delete') {
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $response = callAPI('DELETE', '/votes/' . $vote_id);
        if (!empty($response['success'])) {
            $alert_message = 'رأی‌گیری حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف رأی‌گیری.';
        }
    } elseif ($action === 'update') {
        // ویرایش فقط عنوان/توضیح/تاریخ‌ها — گزینه‌ها پس از شروع رأی‌گیری تغییر نمی‌کنند
        $vote_id = (int) ($_POST['vote_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $alert_message = 'عنوان رأی‌گیری را وارد کنید.';
            $reopen_modal = 'edit-vote';
        } else {
            $response = callAPI('PUT', '/votes/' . $vote_id, [
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'رأی‌گیری ویرایش شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ویرایش رأی‌گیری.';
                $reopen_modal = 'edit-vote';
            }
        }
    } elseif ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $options = [];
        foreach (($_POST['option'] ?? []) as $opt) {
            $opt = trim((string) $opt);
            if ($opt !== '') {
                $options[] = $opt;
            }
        }
        if ($title === '') {
            $alert_message = 'عنوان رأی‌گیری را وارد کنید.';
            $reopen_modal = 'add-vote';
        } elseif (count($options) < 2) {
            $alert_message = 'حداقل ۲ گزینه برای رأی‌گیری وارد کنید.';
            $reopen_modal = 'add-vote';
        } else {
            $response = callAPI('POST', '/votes', [
                'building_id' => $building_id,
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'options' => $options,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'رأی‌گیری با موفقیت ایجاد شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ایجاد رأی‌گیری.';
                $reopen_modal = 'add-vote';
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
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-vote', 'ایجاد رأی‌گیری جدید'); ?>
    <?php else: ?>
        <div class="hint-card">🗳️ رأی‌گیری‌ها توسط مدیر ساختمان ایجاد می‌شوند؛ شما می‌توانید در آن‌ها شرکت کنید.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">رأی‌گیری‌های ساختمان (<?= fa_digits(count($votes)) ?>)</h2>
    </div>

    <?php if (empty($votes)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🗳️</div>
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
                        <span class="chip <?= $is_active ? 'chip-green' : 'chip-gray' ?>">
                            <?= htmlspecialchars(vote_status_label($v_status)) ?>
                        </span>
                    </div>
                    <?php if (!empty($vote['description'])): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($vote['description'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($vote['end_date'])): ?>
                        <p class="text-xs text-gray-400 mt-2">پایان: <?= fa_date($vote['end_date']) ?></p>
                    <?php endif; ?>

                    <?php if ($can_vote): ?>
                        <!-- فرم رأی دادن -->
                        <form method="POST" action="" class="mt-3 space-y-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="cast_vote">
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

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-vote"
                                    data-set-vote_id="<?= (int) $vote['id'] ?>"
                                    data-set-title="<?= htmlspecialchars($vote['title'] ?? '') ?>"
                                    data-set-description="<?= htmlspecialchars($vote['description'] ?? '') ?>"
                                    data-set-start_date="<?= htmlspecialchars($vote['start_date'] ?? '') ?>"
                                    data-set-end_date="<?= htmlspecialchars($vote['end_date'] ?? '') ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="update_status">
                                <input type="hidden" name="vote_id" value="<?= (int) $vote['id'] ?>">
                                <input type="hidden" name="status" value="<?= $is_active ? 'closed' : 'active' ?>">
                                <button type="submit" class="btn-chip btn-chip-neutral">
                                    <?= $is_active ? 'بستن رأی‌گیری' : 'بازکردن دوباره' ?>
                                </button>
                            </form>
                            <form method="POST" action="" data-confirm="این رأی‌گیری برای همیشه حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="vote_id" value="<?= (int) $vote['id'] ?>">
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

    <?php modal_start('add-vote', 'ایجاد رأی‌گیری جدید', 'عنوان، گزینه‌ها و بازه زمانی'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <div>
                <label class="form-label">عنوان رأی‌گیری *</label>
                <input type="text" name="title" required class="form-input" placeholder="مثال: رنگ‌آمیزی نمای ساختمان">
            </div>
            <div>
                <label class="form-label">توضیحات (اختیاری)</label>
                <textarea name="description" rows="2" class="form-input" placeholder="شرح موضوع رأی‌گیری..."></textarea>
            </div>
            <div>
                <label class="form-label">گزینه‌ها * (حداقل ۲ گزینه)</label>
                <div id="options-container" class="space-y-2">
                    <input type="text" name="option[]" required class="form-input" placeholder="گزینه ۱">
                    <input type="text" name="option[]" required class="form-input" placeholder="گزینه ۲">
                    <input type="text" name="option[]" class="form-input" placeholder="گزینه ۳ (اختیاری)">
                </div>
                <button type="button" id="add-option-btn" class="btn-chip btn-chip-neutral" style="margin-top:8px;">
                    + افزودن گزینه دیگر
                </button>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">شروع</label>
                    <input type="date" name="start_date" class="form-input">
                </div>
                <div>
                    <label class="form-label">پایان</label>
                    <input type="date" name="end_date" class="form-input">
                </div>
            </div>
            <button type="submit" class="btn-primary">ایجاد رأی‌گیری</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-vote', 'ویرایش رأی‌گیری', 'گزینه‌ها پس از ایجاد قابل تغییر نیستند'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="vote_id" value="">
            <div>
                <label class="form-label">عنوان رأی‌گیری *</label>
                <input type="text" name="title" required class="form-input">
            </div>
            <div>
                <label class="form-label">توضیحات</label>
                <textarea name="description" rows="3" class="form-input"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">شروع</label>
                    <input type="date" name="start_date" class="form-input">
                </div>
                <div>
                    <label class="form-label">پایان</label>
                    <input type="date" name="end_date" class="form-input">
                </div>
            </div>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

    <script>
        /* افزودن گزینه‌های بیشتر در فرم ایجاد رأی‌گیری */
        (function () {
            var btn = document.getElementById('add-option-btn');
            var container = document.getElementById('options-container');
            if (!btn || !container) { return; }
            btn.addEventListener('click', function () {
                var input = document.createElement('input');
                input.type = 'text';
                input.name = 'option[]';
                input.className = 'form-input';
                input.placeholder = 'گزینه جدید';
                container.appendChild(input);
                input.focus();
            });
        })();
    </script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
