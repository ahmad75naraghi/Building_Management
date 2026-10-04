<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$page_title = 'راهنمای استفاده';
$header_sub = 'آموزش گام‌به‌گام سامانه';
$back_url = 'profile.php';
$active_nav = 'profile';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- ==================== شروع از کجا؟ ==================== -->
    <section class="card p-4" style="margin-bottom:14px;border:1.5px solid var(--gold-primary);">
        <h2 class="section-title" style="margin-bottom:8px;">🚀 از کجا شروع کنم؟</h2>
        <p style="font-size:12px;color:var(--text-gray);line-height:2;">
            اگر تازه وارد شده‌اید، این چهار گام را به‌ترتیب انجام دهید. هر گام کمتر از چند دقیقه طول می‌کشد
            و بعد از آن، سامانه خودش کارهای تکراری را انجام می‌دهد.
        </p>
        <div class="space-y-2" style="margin-top:10px;">
            <?php
            $help_steps = [
                ['۱', 'ساختمان را ثبت کنید', 'از صفحهٔ «ساختمان‌ها» دکمهٔ ثبت ساختمان جدید را بزنید؛ نام، آدرس و تعداد طبقات و واحدها کافی است — واحدها خودکار ساخته می‌شوند.', 'index.php'],
                ['۲', 'ساکنین را دعوت کنید', 'در بخش «اعضا»، لینک دعوت پیامکی بفرستید. هر ساکن با شمارهٔ خودش وارد می‌شود و شما او را به واحدش متصل می‌کنید.', 'members.php' . ($building_id ? '?building_id=' . $building_id : '')],
                ['۳', 'شارژ و هزینه ثبت کنید', 'در بخش «هزینه‌ها»، شارژ ماهانه یا هر هزینه‌ای را ثبت و «صادر» کنید تا بین واحدها تقسیم شود.', 'costs.php' . ($building_id ? '?building_id=' . $building_id : '')],
                ['۴', 'پرداخت‌ها را تأیید کنید', 'ساکنین فیش پرداخت می‌فرستند؛ شما در همان بخش هزینه‌ها تأیید می‌کنید و مبلغ به حساب واحد می‌نشیند.', 'costs.php' . ($building_id ? '?building_id=' . $building_id : '')],
            ];
            foreach ($help_steps as $hs): ?>
                <div class="flex items-start gap-3 p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);">
                    <span class="flex items-center justify-center flex-shrink-0" style="width:26px;height:26px;border-radius:9px;background:var(--gold-primary);color:#fff;font-size:12px;font-weight:900;"><?= $hs[0] ?></span>
                    <span style="flex:1;min-width:0;">
                        <span style="display:block;font-size:13px;font-weight:800;color:var(--text-dark);"><?= $hs[1] ?></span>
                        <span style="display:block;font-size:11px;color:var(--text-gray);line-height:1.9;margin-top:2px;"><?= $hs[2] ?></span>
                    </span>
                    <?php if ($building_id > 0 || !str_contains($hs[3], 'building_id')): ?>
                    <a href="<?= htmlspecialchars($hs[3]) ?>" class="btn-chip btn-chip-gold" style="text-decoration:none;align-self:center;white-space:nowrap;">برو</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ==================== نقش‌ها ==================== -->
    <section class="card p-4" style="margin-bottom:14px;">
        <h2 class="section-title" style="margin-bottom:8px;">👥 نقش من چیست؟</h2>
        <div class="space-y-2">
            <div class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);">
                <span style="display:block;font-size:13px;font-weight:800;color:var(--text-dark);">⭐ مدیر ساختمان</span>
                <span style="display:block;font-size:11px;color:var(--text-gray);line-height:1.9;margin-top:2px;">
                    ثبت هزینه و صدور آن، تأیید یا رد پرداخت‌ها، تعریف بلوک/طبقه/واحد، دعوت اعضا، تأیید رزروها،
                    مدیریت جلسات و اطلاعیه‌ها را انجام می‌دهد.
                </span>
            </div>
            <div class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);">
                <span style="display:block;font-size:13px;font-weight:800;color:var(--text-dark);">🏠 ساکن (مالک یا مستأجر)</span>
                <span style="display:block;font-size:11px;color:var(--text-gray);line-height:1.9;margin-top:2px;">
                    مبلغ شارژ و بدهی خود را می‌بیند، فیش پرداخت می‌فرستد، مشاعات را رزرو می‌کند،
                    تیکت می‌زند، در رأی‌گیری‌ها شرکت می‌کند و با مدیر پیام می‌دهد.
                </span>
            </div>
        </div>
    </section>

    <!-- ==================== پول چطور کار می‌کند؟ ==================== -->
    <section class="card p-4" style="margin-bottom:14px;">
        <h2 class="section-title" style="margin-bottom:8px;">💰 چرخهٔ پول در سامانه</h2>
        <div style="font-size:12px;color:var(--text-gray);line-height:2.2;">
            <p style="margin-bottom:8px;">
                <strong style="color:var(--text-dark);">۱. ثبت هزینه</strong> — مدیر هزینه را ثبت می‌کند (شارژ ماهانه، تعمیر آسانسور و…).
                <br>
                <strong style="color:var(--text-dark);">۲. صدور</strong> — هزینه «صادر» می‌شود؛ یعنی سهم هر واحد محاسبه و برایش قابل پرداخت می‌شود.
                <br>
                <strong style="color:var(--text-dark);">۳. پرداخت ساکن</strong> — ساکن مبلغ را واریز و عکس فیش را در اپ ثبت می‌کند.
                <br>
                <strong style="color:var(--text-dark);">۴. تأیید مدیر</strong> — مدیر فیش را تأیید می‌کند؛ مبلغ به حساب واحد می‌نشیند و مانده کم می‌شود.
            </p>
            <p>
                حالت‌های شارژ: <strong style="color:var(--text-dark);">ثابت</strong> (مبلغ یکسان برای همه)،
                <strong style="color:var(--text-dark);">نفری</strong> (به تعداد ساکنین هر واحد) و
                <strong style="color:var(--text-dark);">ترکیبی</strong> (ثابت + نفری با هم) از «ویرایش ساختمان» قابل تنظیم است.
            </p>
        </div>
    </section>

    <!-- ==================== معرفی بخش‌ها ==================== -->
    <section class="card p-4" style="margin-bottom:14px;">
        <h2 class="section-title" style="margin-bottom:10px;">🧩 هر بخش چه کار می‌کند؟</h2>
        <div class="space-y-2">
            <?php
            $help_sections = [
                ['🏠', 'داشبورد', 'خلاصهٔ همه‌چیز: مانده‌ها، نمودارها، بدهکاران و نمای گرافیکی ساختمان', 'dashboard.php'],
                ['💰', 'هزینه‌ها', 'ثبت شارژ و هزینه، صدور، و تأیید فیش‌های پرداخت', 'costs.php'],
                ['📊', 'حسابداری', 'گردش مالی و ماندهٔ هر واحد + جریمهٔ تأخیر', 'accounting.php'],
                ['📈', 'گزارش‌ها', 'گزارش ماهانهٔ درآمد/هزینه و فهرست بدهکاران با خروجی اکسل', 'reports.php'],
                ['👥', 'اعضا', 'فهرست ساکنین، نقش‌ها و دعوت عضو جدید با پیامک', 'members.php'],
                ['🏢', 'ساختار', 'بلوک‌ها، طبقات، واحدها و مشاعات ساختمان', 'units.php'],
                ['📅', 'تقویم', 'نمایش شمسی جلسه‌ها، رزروها و مهلت پرداخت‌ها', 'calendar.php'],
                ['📢', 'اطلاعیه‌ها', 'اعلان عمومی برای همهٔ ساکنین', 'announcements.php'],
                ['🗳️', 'رأی‌گیری', 'تصمیم‌گیری جمعی ساکنین', 'votes.php'],
                ['🤝', 'جلسات', 'زمان‌بندی جلسات و صورت‌جلسه', 'meetings.php'],
                ['🎫', 'تیکت‌ها', 'اعلام مشکل توسط ساکن و پیگیری توسط مدیر', 'tickets.php'],
                ['📎', 'اسناد', 'نگهداری امن مدارک با دسترسی انتخابی', 'documents.php'],
                ['📅', 'رزروها', 'رزرو مشاعات توسط ساکنین و تأیید مدیر', 'bookings.php'],
                ['🔧', 'تعمیرات', 'درخواست‌های تعمیر و نگهداری', 'maintenance.php'],
                ['🚶', 'مهمان‌ها', 'ثبت مراجعه‌کنندگان و پلاک خودرو', 'visitors.php'],
                ['💧', 'مصارف', 'قرائت دوره‌ای کنتورهای آب، برق و گاز', 'consumption.php'],
                ['☎️', 'تماس اضطراری', 'شماره‌های ضروری برای ساکنین', 'emergency_contacts.php'],
                ['💬', 'پیام‌ها', 'گفتگوی خصوصی بین ساکنین ساختمان', 'messages.php'],
                ['🔍', 'جستجو', 'پیدا کردن سریع واحد، ساکن، هزینه و تیکت — از دکمهٔ ذره‌بین بالای صفحات', 'search.php'],
                ['🗄️', 'پشتیبان‌گیری', 'پشتیبان کامل دیتابیس؛ فقط برای شماره‌های مجاز مدیر سیستم', 'backups.php'],
            ];
            foreach ($help_sections as $sec): ?>
                <a href="<?= $building_id > 0 ? htmlspecialchars($sec[3] . '?building_id=' . $building_id) : '#' ?>"
                   class="flex items-center gap-3 p-2.5 rounded-xl transition-colors" style="background:var(--soft-gray,#f8fafc);text-decoration:none;">
                    <span style="font-size:18px;" aria-hidden="true"><?= $sec[0] ?></span>
                    <span style="flex:1;min-width:0;">
                        <span style="display:block;font-size:12.5px;font-weight:800;color:var(--text-dark);"><?= $sec[1] ?></span>
                        <span style="display:block;font-size:10.5px;color:var(--text-gray);line-height:1.8;"><?= $sec[2] ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($building_id <= 0): ?>
            <p style="font-size:10.5px;color:var(--text-gray);margin-top:8px;">برای باز شدن هر بخش، ابتدا از صفحهٔ اصلی یک ساختمان انتخاب کنید.</p>
        <?php endif; ?>
    </section>

    <!-- ==================== پرسش‌های رایج ==================== -->
    <section class="card p-4" style="margin-bottom:14px;">
        <h2 class="section-title" style="margin-bottom:8px;">❓ پرسش‌های رایج</h2>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);margin-bottom:8px;">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">ساکن چطور وارد اپ شود؟</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                از بخش «اعضا» برایش لینک دعوت پیامکی بفرستید. ساکن با همان شماره وارد می‌شود،
                نام و رمز انتخاب می‌کند و شما او را به واحدش متصل می‌کنید.
            </p>
        </details>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);margin-bottom:8px;">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">واحد‌ها را تک‌تک بسازم؟</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                نه! سه راه سریع دارید: ۱) هنگام ثبت ساختمان، تعداد طبقات و واحدها را بدهید تا خودکار ساخته شوند؛
                ۲) در صفحهٔ واحدها، دکمهٔ «ایمپورت از فایل» تا ۵۰۰ واحد را با یک فایل اکسل می‌سازد؛
                ۳) دکمهٔ «دادهٔ نمونه» ساختمان را برای آشنایی با ساکن و هزینهٔ آزمایشی پر می‌کند.
            </p>
        </details>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);margin-bottom:8px;">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">رسید پرداخت را چطور به مالک بدهم؟</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                در بخش هزینه‌ها، روی هر پرداخت «چاپ رسید پرداخت» را بزنید؛ صفحهٔ رسید رسمی باز می‌شود
                و از همان‌جا می‌توانید چاپ یا به‌صورت PDF ذخیره کنید. صورت‌حساب ماهانهٔ هر واحد هم از بخش گزارش‌ها قابل ارسال است.
            </p>
        </details>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);margin-bottom:8px;">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">اگر فیش پرداخت اشتباه بود چه؟</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                در بخش هزینه‌ها، پرداخت را «رد» کنید؛ ساکن اعلان می‌گیرد و می‌تواند دوباره تلاش کند.
            </p>
        </details>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);margin-bottom:8px;">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">اپ بدون اینترنت هم کار می‌کند؟</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                بله؛ سامانه قابل نصب روی گوشی است (نصب اپ) و صفحات دیده‌شده به‌صورت آفلاین هم باز می‌شوند.
                ارسال اطلاعات البته به اینترنت نیاز دارد.
            </p>
        </details>
        <details class="p-3 rounded-xl" style="background:var(--soft-gray,#f8fafc);">
            <summary style="font-size:12.5px;font-weight:800;color:var(--text-dark);cursor:pointer;">یادم رفت صفحه‌ها چه بود!</summary>
            <p style="font-size:11px;color:var(--text-gray);line-height:2;margin-top:6px;">
                زیر عنوان هر صفحه یک خط توضیح دارد که کار همان بخش را می‌گوید. این صفحهٔ راهنما هم همیشه از «پروفایل ← راهنمای استفاده» در دسترس است.
            </p>
        </details>
    </section>

    <a href="index.php" class="btn-primary" style="text-decoration:none;">بازگشت به ساختمان‌ها</a>

</main>

<?php require_once 'includes/page_tail.php'; ?>
