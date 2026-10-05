<?php
/** تست اسناد ساختمان: آپلود امن، دسته‌بندی، رویت اعضا و پاک‌سازی */
declare(strict_types=1);

use App\Models\Document;
use App\Services\ExtraModulesService;
use App\Utilities\FileStorage;

TestLog::suite('Documents — آپلود امن، دسته‌بندی و کنترل رویت');

$db = test_db();
$svc = new ExtraModulesService();
$manager = make_user('09134000001', 'مدیر اسناد');
$member = make_user('09134000002', 'ساکن ساختمان');

/** یک PDF حداقلی که finfo آن را application/pdf تشخیص می‌دهد */
function doc_sample_pdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
}

/** حذف بازگشتی پوشه (برای پاک‌سازی artifacts تست) */
function doc_rm_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $p = $dir . '/' . $entry;
        is_dir($p) ? doc_rm_dir($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ------------------------------------------------------------ دسته‌بندی

TestLog::run('دسته‌بندی‌های رسمی ۸ مورد است', function () {
    TestLog::assertSame('تعداد دسته‌ها', 8, count(Document::CATEGORIES));
});

TestLog::run('نرمال‌سازی دسته‌ها', function () {
    TestLog::assertSame('دسته معتبر دست‌نخورده', 'meeting', Document::normalizeCategory('meeting'));
    TestLog::assertSame('دسته ناشناخته → سایر', 'other', Document::normalizeCategory('دسته عجیب'));
    TestLog::assertSame('مقدار قدیمی general → سایر', 'other', Document::normalizeCategory('general'));
    TestLog::assertSame('خالی → سایر', 'other', Document::normalizeCategory(null));
});

// ------------------------------------------------------------ کنترل دسترسی مدیر

TestLog::run('ثبت سند فقط برای مدیر مجاز است', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');

    TestLog::assertThrows('عضو عادی سند لینکی را رد می‌کند', fn() => $svc->createDocument([
        'building_id' => $b, 'title' => 'سند', 'file_path' => 'https://example.com/a.pdf',
    ], $member), 'مدیر');

    TestLog::assertThrows('عضو عادی آپلود را رد می‌کند', fn() => $svc->uploadDocument([
        'building_id' => $b, 'title' => 'سند',
    ], $member, doc_sample_pdf(), 'a.pdf'), 'مدیر');

    $doc = $svc->createDocument([
        'building_id' => $b, 'title' => 'اساسنامه', 'file_path' => 'https://example.com/a.pdf',
    ], $manager);
    TestLog::assertTrue('مدیر سند لینکی ثبت کرد', $doc->id !== null);
    TestLog::assertSame('سند لینکی فایل ذخیره‌شده ندارد', false, $doc->isUploadedFile());
});

// ------------------------------------------------------------ آپلود امن فایل

TestLog::run('آپلود فایل با نام تصادفی و خارج از دسترس وب', function () use ($svc, $manager) {
    $b = make_building($manager);
    $pdf = doc_sample_pdf();
    $doc = $svc->uploadDocument([
        'building_id' => $b, 'title' => 'بیمه نامه', 'document_type' => 'insurance',
    ], $manager, $pdf, 'insurance.pdf');

    TestLog::assertTrue('سند فایل دارد', $doc->isUploadedFile());
    TestLog::assertTrue('نام تصادفی ۳۲ هگز + پسوند', preg_match('/^[a-f0-9]{32}\.pdf$/', (string) $doc->stored_name) === 1);
    TestLog::assertSame('نوع فایل پی‌دی‌اف', 'application/pdf', $doc->mime_type);
    TestLog::assertSame('اندازه فایل', strlen($pdf), $doc->file_size);

    $path = FileStorage::documentPath($b, (string) $doc->stored_name);
    TestLog::assertTrue('فایل روی دیسک وجود دارد', is_file($path));
    TestLog::assertTrue('پوشه اسناد .htaccess محافظ دارد', is_file(dirname($path, 2) . '/.htaccess'));

    doc_rm_dir(dirname($path, 2));
});

TestLog::run('نوع فایل غیرمجاز و فایل خالی رد می‌شوند', function () use ($svc, $manager) {
    $b = make_building($manager);
    TestLog::assertThrows('فایل فشرده مجاز نیست', fn() => $svc->uploadDocument([
        'building_id' => $b, 'title' => 'zip',
    ], $manager, "PK\x03\x04fakezip", 'a.zip'), 'مجاز');
    TestLog::assertThrows('فایل خالی مجاز نیست', fn() => $svc->uploadDocument([
        'building_id' => $b, 'title' => 'خالی',
    ], $manager, '', 'a.pdf'), 'خالی');
});

// ------------------------------------------------------------ کنترل رویت اعضا

TestLog::run('اسناد غیرقابل رویت فقط برای مدیران', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');

    $hidden = $svc->uploadDocument([
        'building_id' => $b, 'title' => 'سند محرمانه', 'is_visible_to_members' => 0,
    ], $manager, doc_sample_pdf(), 'secret.pdf');
    $public = $svc->createDocument([
        'building_id' => $b, 'title' => 'صورت‌جلسه', 'file_path' => 'https://example.com/m.pdf',
        'document_type' => 'meeting', 'is_visible_to_members' => 1,
    ], $manager);

    $memberDocs = $svc->listDocuments($b, $member);
    $managerDocs = $svc->listDocuments($b, $manager);
    TestLog::assertSame('عضو فقط سند قابل رویت را می‌بیند', 1, count($memberDocs));
    TestLog::assertSame('مدیر هر دو سند را می‌بیند', 2, count($managerDocs));
    TestLog::assertSame('سند قابل رویت همان صورت‌جلسه است', $public->id, $memberDocs[0]->id);

    TestLog::assertThrows('عضو به سند مخفی دسترسی ندارد', fn() => $svc->getDocumentForUser((int) $hidden->id, $member), 'دسترسی');
    $seen = $svc->getDocumentForUser((int) $hidden->id, $manager);
    TestLog::assertSame('مدیر سند مخفی را می‌بیند', $hidden->id, $seen->id);

    foreach ($managerDocs as $d) {
        if ($d->stored_name !== null) {
            @unlink(FileStorage::documentPath($b, (string) $d->stored_name));
        }
    }
});

// ------------------------------------------------------------ تعویض فایل و حذف

TestLog::run('تعویض فایل، فایل قدیمی را حذف می‌کند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $doc = $svc->uploadDocument([
        'building_id' => $b, 'title' => 'قرارداد',
    ], $manager, doc_sample_pdf(), 'contract.pdf');
    $oldName = (string) $doc->stored_name;
    $oldPath = FileStorage::documentPath($b, $oldName);

    $updated = $svc->replaceDocumentFile((int) $doc->id, $manager, doc_sample_pdf() . "\n%v2", 'v2.pdf');
    TestLog::assertTrue('نام فایل عوض شد', $updated->stored_name !== $oldName);
    TestLog::assertTrue('فایل قدیمی حذف شد', !is_file($oldPath));
    TestLog::assertTrue('فایل جدید روی دیسک است', is_file(FileStorage::documentPath($b, (string) $updated->stored_name)));

    doc_rm_dir(\App\Config\AppConfig::STORAGE_PATH . '/documents/' . $b);
});

TestLog::run('حذف سند، فایل را هم پاک می‌کند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $doc = $svc->uploadDocument([
        'building_id' => $b, 'title' => 'سند حذفی',
    ], $manager, doc_sample_pdf(), 'del.pdf');
    $path = FileStorage::documentPath($b, (string) $doc->stored_name);
    TestLog::assertTrue('فایل پیش از حذف موجود است', is_file($path));

    $deleted = $svc->deleteEntity('documents', (int) $doc->id, $manager);
    TestLog::assertTrue('رکورد حذف شد', $deleted);
    TestLog::assertTrue('فایل هم حذف شد', !is_file($path));
});

TestLog::run('نام فایل نامعتبر امکان پیمایش مسیر نمی‌دهد', function () use ($manager) {
    $b = make_building($manager);
    TestLog::assertThrows('پیمایش مسیر رد می‌شود', fn() => FileStorage::documentPath($b, '../../etc/passwd'), 'معتبر');
    TestLog::assertThrows('نام با قالب اشتباه رد می‌شود', fn() => FileStorage::documentPath($b, 'secret.pdf'), 'معتبر');
});

TestLog::run('ویرایش متادیتای سند فقط برای مدیر', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');
    $doc = $svc->createDocument([
        'building_id' => $b, 'title' => 'اصلی', 'file_path' => 'https://example.com/x.pdf',
    ], $manager);

    TestLog::assertThrows('عضو نمی‌تواند ویرایش کند', fn() => $svc->updateEntity('documents', (int) $doc->id, ['title' => 'هک'], $member), 'مدیر');
    $updated = $svc->updateEntity('documents', (int) $doc->id, ['title' => 'ویرایش مدیر', 'is_visible_to_members' => 0], $manager);
    TestLog::assertSame('عنوان ویرایش شد', 'ویرایش مدیر', $updated['title'] ?? '');
    TestLog::assertSame('رویت غیرفعال شد', 0, (int) ($updated['is_visible_to_members'] ?? 1));
});

// پاک‌سازی نهایی پوشه اسناد تست
doc_rm_dir(\App\Config\AppConfig::STORAGE_PATH . '/documents');
