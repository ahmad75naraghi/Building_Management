<?php
require_once 'includes/api_helper.php';

use App\Models\Document;

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// اسناد ساختمان فقط توسط مدیر ثبت/ویرایش/حذف می‌شوند؛ بقیه فقط مشاهده و دانلود.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند اسناد را مدیریت کند.';
    } elseif ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $response = callAPI('DELETE', '/documents/' . $item_id);
        if (!empty($response['success'])) {
            $alert_message = 'سند و فایل آن حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف سند.';
        }
    } elseif ($action === 'update') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($item_id <= 0 || $title === '') {
            $alert_message = 'عنوان سند الزامی است.';
            $reopen_modal = 'edit-document';
        } else {
            $payload = [
                'title' => $title,
                'document_type' => Document::normalizeCategory($_POST['document_type'] ?? null),
                'is_visible_to_members' => isset($_POST['is_visible_to_members']) ? 1 : 0,
            ];
            // اسناد لینکی: آدرس فایل هم قابل ویرایش است
            if (($_POST['has_link'] ?? '') === '1') {
                $file_path = trim($_POST['file_path'] ?? '');
                if ($file_path === '') {
                    $alert_message = 'آدرس فایل (لینک) الزامی است.';
                    $reopen_modal = 'edit-document';
                } else {
                    $payload['file_path'] = $file_path;
                }
            }

            if ($alert_message === '') {
                $response = callAPI('PUT', '/documents/' . $item_id, $payload);
                if (empty($response['success'])) {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش سند.';
                    $reopen_modal = 'edit-document';
                } else {
                    $alert_message = 'سند ویرایش شد.';
                    $alert_type = 'success';

                    // تعویض فایل سند (اختیاری)
                    if (!empty($_FILES['file']['tmp_name']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $replace = callAPIUpload('/documents/' . $item_id . '/replace-file', [], [
                            'file' => $_FILES['file']['tmp_name'],
                        ]);
                        if (empty($replace['success'])) {
                            $alert_message = 'اطلاعات سند ذخیره شد اما تعویض فایل ناموفق بود: ' . ($replace['message'] ?? 'خطای نامشخص');
                            $alert_type = 'error';
                        } else {
                            $alert_message = 'سند و فایل آن با موفقیت به‌روزرسانی شد.';
                        }
                    }
                }
            }
        }
    } else { // create
        $title = trim($_POST['title'] ?? '');
        $source = ($_POST['source'] ?? 'file') === 'link' ? 'link' : 'file';
        $visible = isset($_POST['is_visible_to_members']) ? 1 : 0;
        $category = Document::normalizeCategory($_POST['document_type'] ?? null);

        if ($title === '') {
            $alert_message = 'عنوان سند الزامی است.';
            $reopen_modal = 'add-document';
        } elseif ($source === 'link') {
            $file_path = trim($_POST['file_path'] ?? '');
            if ($file_path === '') {
                $alert_message = 'آدرس فایل (لینک) الزامی است.';
                $reopen_modal = 'add-document';
            } else {
                $response = callAPI('POST', '/documents', [
                    'building_id' => $building_id,
                    'title' => $title,
                    'file_path' => $file_path,
                    'document_type' => $category,
                    'is_visible_to_members' => $visible,
                ]);
                if (!empty($response['success'])) {
                    $alert_message = 'سند با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت سند.';
                    $reopen_modal = 'add-document';
                }
            }
        } else {
            if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $alert_message = 'انتخاب فایل سند الزامی است.';
                $reopen_modal = 'add-document';
            } else {
                $response = callAPIUpload('/documents', [
                    'building_id' => $building_id,
                    'title' => $title,
                    'document_type' => $category,
                    'is_visible_to_members' => (string) $visible,
                ], [
                    'file' => $_FILES['file']['tmp_name'],
                ]);
                if (!empty($response['success'])) {
                    $alert_message = 'سند با موفقیت بارگذاری شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در بارگذاری سند.';
                    $reopen_modal = 'add-document';
                }
            }
        }
    }
}

// دریافت لیست اسناد
$documents = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/documents', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $documents = $list_response['data'] ?? [];
    }
}

/* دسته‌بندی اسناد برای نمایش گروهی */
$grouped = [];
foreach ($documents as $doc) {
    $key = Document::normalizeCategory($doc['document_type'] ?? null);
    $grouped[$key][] = $doc;
}

/* آیکون و رنگ بر اساس نوع فایل */
function doc_file_icon(array $doc): array
{
    if (empty($doc['is_uploaded'])) {
        return ['🔗', '#eef2ff', '#4f46e5'];
    }
    $mime = (string) ($doc['mime_type'] ?? '');
    return match (true) {
        str_contains($mime, 'pdf') => ['📄', '#fef2f2', '#dc2626'],
        str_starts_with($mime, 'image/') => ['🖼️', '#ecfdf5', '#059669'],
        str_contains($mime, 'word'), str_contains($mime, 'msword') => ['📝', '#eff6ff', '#2563eb'],
        str_contains($mime, 'excel'), str_contains($mime, 'spreadsheet') => ['📊', '#f0fdf4', '#16a34a'],
        str_starts_with($mime, 'text/') => ['📃', '#f5f3ff', '#7c3aed'],
        default => ['📎', '#f3f4f6', '#4b5563'],
    };
}

/* حجم فایل به صورت خوانا و فارسی */
function doc_fa_size(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '';
    }
    if ($bytes < 1024) {
        return fa_digits($bytes) . ' بایت';
    }
    if ($bytes < 1048576) {
        return fa_digits(round($bytes / 1024)) . ' کیلوبایت';
    }
    return fa_digits(number_format($bytes / 1048576, 1)) . ' مگابایت';
}

$page_title = 'اسناد ساختمان';
$header_sub = $building_name ?: 'آرشیو اسناد';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-document', '📤 بارگذاری سند جدید'); ?>
    <?php else: ?>
        <div class="hint-card">📄 اسناد ساختمان توسط مدیر بارگذاری می‌شوند؛ شما می‌توانید اسناد قابل رویت را مشاهده و دانلود کنید.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">اسناد و مدارک (<?= fa_digits(count($documents)) ?>)</h2>
    </div>

    <?php if (empty($documents)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">📂</div>
            هنوز سندی ثبت نشده است.
            <?php if ($is_manager): ?><br><span class="muted" style="font-size:12px;">از دکمه بالا اولین سند را بارگذاری کنید.</span><?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach (Document::CATEGORIES as $cat_key => $cat_label): ?>
            <?php if (empty($grouped[$cat_key])) { continue; } ?>
            <div class="doc-group">
                <div class="doc-group-title">
                    <span><?= htmlspecialchars($cat_label) ?></span>
                    <span class="doc-group-count"><?= fa_digits(count($grouped[$cat_key])) ?></span>
                </div>

                <div class="space-y-3">
                    <?php foreach ($grouped[$cat_key] as $document): ?>
                        <?php
                        $d_id = (int) ($document['id'] ?? 0);
                        $d_title = $document['title'] ?? 'بدون عنوان';
                        $d_uploaded = !empty($document['is_uploaded']);
                        $d_visible = (int) ($document['is_visible_to_members'] ?? 1) === 1;
                        $d_path = (string) ($document['file_path'] ?? '');
                        [$d_icon, $d_bg, $d_fg] = doc_file_icon($document);
                        ?>
                        <div class="card p-4 doc-card">
                            <div class="flex items-center gap-3">
                                <div class="doc-icon" style="background: <?= $d_bg ?>; color: <?= $d_fg ?>;"><?= $d_icon ?></div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-bold text-gray-800 text-sm truncate" title="<?= htmlspecialchars($d_title) ?>"><?= htmlspecialchars($d_title) ?></h3>
                                    <div class="doc-meta">
                                        <span>🗓 <?= fa_datetime($document['created_at'] ?? '') ?></span>
                                        <?php $size_label = doc_fa_size(isset($document['file_size']) ? (int) $document['file_size'] : null); ?>
                                        <?php if ($size_label !== ''): ?><span>💾 <?= $size_label ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="doc-actions">
                                    <?php if ($d_uploaded): ?>
                                        <a href="document_download.php?id=<?= $d_id ?>" class="btn-chip btn-chip-neutral">مشاهده</a>
                                        <a href="document_download.php?id=<?= $d_id ?>&amp;dl=1" class="btn-chip btn-chip-ghost">دانلود</a>
                                    <?php elseif ($d_path !== '' && preg_match('#^https?://#i', $d_path)): ?>
                                        <a href="<?= htmlspecialchars($d_path) ?>" target="_blank" rel="noopener" class="btn-chip btn-chip-neutral">مشاهده 🔗</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($is_manager): ?>
                                <div class="card-actions">
                                    <?php if ($d_visible): ?>
                                        <span class="chip chip-green">👥 قابل رویت اعضا</span>
                                    <?php else: ?>
                                        <span class="chip chip-red">🔒 فقط مدیران</span>
                                    <?php endif; ?>
                                    <span style="flex:1;"></span>
                                    <button type="button" class="btn-chip btn-chip-edit"
                                            data-modal-open="edit-document"
                                            data-set-item_id="<?= $d_id ?>"
                                            data-set-title="<?= htmlspecialchars($d_title) ?>"
                                            data-set-document_type="<?= htmlspecialchars(Document::normalizeCategory($document['document_type'] ?? null)) ?>"
                                            data-set-is_visible_to_members="<?= $d_visible ? '1' : '0' ?>"
                                            data-set-has_link="<?= $d_uploaded ? '0' : '1' ?>"
                                            <?php if (!$d_uploaded): ?>data-set-file_path="<?= htmlspecialchars($d_path) ?>"<?php endif; ?>>
                                        ویرایش
                                    </button>
                                    <form method="POST" action="" data-confirm="این سند و فایل آن حذف شود؟" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="form_action" value="delete">
                                        <input type="hidden" name="item_id" value="<?= $d_id ?>">
                                        <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>
    <?php modal_start('add-document', 'بارگذاری سند جدید', 'فایل با نام و دسته‌بندی دلخواه — دسترسی فقط برای مدیران'); ?>
        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <?php $document_form_mode = 'add'; include 'includes/_document_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت سند</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-document', 'ویرایش سند', 'اصلاح عنوان، دسته، رویت و فایل سند'); ?>
        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="item_id" value="">
            <input type="hidden" name="has_link" value="0">
            <?php $document_form_mode = 'edit'; include 'includes/_document_form_fields.php'; ?>
            <div data-doc-link-row style="display:none;">
                <label class="form-label">آدرس فایل (لینک)</label>
                <input type="url" name="file_path" dir="ltr" class="form-input" style="text-align:left;" placeholder="https://example.com/file.pdf">
            </div>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
        <script>
            (function () {
                /* نمایش فیلد «لینک» فقط برای اسناد لینکی هنگام ویرایش */
                var scope = document.currentScript.parentElement;
                var flag = scope.querySelector('input[name="has_link"]');
                var row = scope.querySelector('[data-doc-link-row]');
                function sync() {
                    row.style.display = flag.value === '1' ? '' : 'none';
                }
                flag.addEventListener('change', sync);
                sync();
            })();
        </script>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
