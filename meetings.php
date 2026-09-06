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

// جلسات ساختمان توسط مدیر ایجاد/ویرایش/حذف می‌شوند؛ ساکنین فقط مشاهده می‌کنند.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند جلسات را مدیریت کند.';
    } elseif ($action === 'update_status') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($meeting_id > 0 && in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
            $response = callAPI('PUT', '/meetings/' . $meeting_id . '/status', ['status' => $status]);
            if (!empty($response['success'])) {
                $alert_message = 'وضعیت جلسه به‌روزرسانی شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت جلسه.';
            }
        }
    } elseif ($action === 'add_minutes') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        $content = trim($_POST['minutes_content'] ?? '');
        if ($meeting_id > 0 && $content !== '') {
            $response = callAPI('POST', '/meetings/' . $meeting_id . '/minutes', ['minutes_content' => $content]);
            if (!empty($response['success'])) {
                $alert_message = 'صورت‌جلسه ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت صورت‌جلسه.';
                $reopen_modal = 'add-minutes';
            }
        } else {
            $alert_message = 'متن صورت‌جلسه را وارد کنید.';
            $reopen_modal = 'add-minutes';
        }
    } elseif ($action === 'delete') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        $response = callAPI('DELETE', '/meetings/' . $meeting_id);
        if (!empty($response['success'])) {
            $alert_message = 'جلسه حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف جلسه.';
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $alert_message = 'عنوان جلسه را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-meeting' : 'add-meeting';
        } else {
            $payload = [
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'meeting_date' => !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null,
                'location' => trim($_POST['location'] ?? ''),
            ];
            if ($action === 'update') {
                $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
                $response = callAPI('PUT', '/meetings/' . $meeting_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'جلسه ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش جلسه.';
                    $reopen_modal = 'edit-meeting';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/meetings', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'جلسه با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت جلسه.';
                    $reopen_modal = 'add-meeting';
                }
            }
        }
    }
}

// دریافت لیست جلسات
$meetings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/meetings', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $meetings = $list_response['data'] ?? [];
    }
}

$status_chips = [
    'scheduled' => 'chip-gold',
    'completed' => 'chip-green',
    'cancelled' => 'chip-red',
];

$page_title = 'جلسات';
$header_sub = $building_name ?: 'جلسات ساختمان';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-meeting', 'ثبت جلسه جدید'); ?>
    <?php else: ?>
        <div class="hint-card">🤝 جلسات ساختمان توسط مدیر برنامه‌ریزی می‌شود؛ در ادامه می‌توانید زمان و صورت‌جلسه‌ها را ببینید.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">جلسات ساختمان (<?= fa_digits(count($meetings)) ?>)</h2>
    </div>

    <?php if (empty($meetings)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🤝</div>
            جلسه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($meetings as $meeting): ?>
                <?php
                $m_id = (int) ($meeting['id'] ?? 0);
                $m_title = $meeting['title'] ?? 'بدون عنوان';
                $m_desc = $meeting['description'] ?? '';
                $m_date = $meeting['meeting_date'] ?? '';
                $m_loc = $meeting['location'] ?? '';
                $m_status = $meeting['status'] ?? 'scheduled';
                $m_minutes = $meeting['minutes_content'] ?? '';
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1"><?= htmlspecialchars($m_title) ?></h3>
                        <span class="chip <?= $status_chips[$m_status] ?? 'chip-gray' ?>">
                            <?= htmlspecialchars(meeting_status_label($m_status)) ?>
                        </span>
                    </div>

                    <?php if ($m_desc !== ''): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($m_desc)) ?></p>
                    <?php endif; ?>

                    <?php if ($m_date !== '' || $m_loc !== ''): ?>
                        <p class="text-xs text-gray-400 mt-2">
                            📅 <?= fa_digits($m_date) ?>
                            <?php if ($m_loc !== ''): ?> • 📍 <?= htmlspecialchars($m_loc) ?><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($m_minutes !== ''): ?>
                        <div class="hint-card" style="margin-top:10px;">
                            <strong>صورت‌جلسه:</strong><br><?= nl2br(htmlspecialchars($m_minutes)) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_manager): ?>
                        <div class="card-actions" style="flex-wrap:wrap;">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-meeting"
                                    data-set-meeting_id="<?= $m_id ?>"
                                    data-set-title="<?= htmlspecialchars($m_title) ?>"
                                    data-set-description="<?= htmlspecialchars($m_desc) ?>"
                                    data-set-meeting_date="<?= htmlspecialchars($m_date) ?>"
                                    data-set-location="<?= htmlspecialchars($m_loc) ?>">
                                ویرایش
                            </button>
                            <button type="button" class="btn-chip btn-chip-neutral"
                                    data-modal-open="add-minutes"
                                    data-set-meeting_id="<?= $m_id ?>"
                                    data-set-minutes_content="<?= htmlspecialchars($m_minutes) ?>">
                                صورت‌جلسه
                            </button>
                            <form method="POST" action="" style="display:flex;gap:6px;align-items:center;flex:1;min-width:170px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="update_status">
                                <input type="hidden" name="meeting_id" value="<?= $m_id ?>">
                                <select name="status" class="form-input" style="padding:7px 10px;font-size:11.5px;flex:1;">
                                    <option value="scheduled" <?= $m_status === 'scheduled' ? 'selected' : '' ?>>برنامه‌ریزی شده</option>
                                    <option value="completed" <?= $m_status === 'completed' ? 'selected' : '' ?>>برگزار شده</option>
                                    <option value="cancelled" <?= $m_status === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                                </select>
                                <button type="submit" class="btn-chip btn-chip-neutral">ثبت</button>
                            </form>
                            <form method="POST" action="" data-confirm="این جلسه حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="meeting_id" value="<?= $m_id ?>">
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
    <?php modal_start('add-meeting', 'ثبت جلسه جدید', 'عنوان، زمان و محل برگزاری'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_meeting_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت جلسه</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-meeting', 'ویرایش جلسه', 'اصلاح اطلاعات جلسه'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="meeting_id" value="">
            <?php include 'includes/_meeting_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('add-minutes', 'ثبت صورت‌جلسه', 'خلاصه تصمیمات این جلسه'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_minutes">
            <input type="hidden" name="meeting_id" value="">
            <div>
                <label class="form-label">متن صورت‌جلسه *</label>
                <textarea name="minutes_content" rows="6" required class="form-input" placeholder="تصمیمات و مصوبات جلسه..."></textarea>
            </div>
            <button type="submit" class="btn-primary">ثبت صورت‌جلسه</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
