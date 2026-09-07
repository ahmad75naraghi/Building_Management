# 🏢 Building Management Pro — سیستم مدیریت ساختمان

> نسخه: **1.0.0** | زبان: **PHP 8.3 (Pure PHP — بدون فریم‌ورک سنگین)** | معماری: **MVC + PSR-4 + REST API**
> پایگاه داده: **MySQL 8 / MariaDB 11** | کش و صف: **Redis** | وب‌سرور: **LiteSpeed / Apache**
> احراز هویت: **JWT (HS256)**

سیستم کامل و حرفه‌ای مدیریت ساختمان شامل **REST API** (برای اپ موبایل و فرانت‌اندها) + **پنل وب ریسپانسیو فارسی** (موبایل‌فرست) برای مدیر و ساکنان ساختمان.

- 📦 کالکشن کامل Postman (۱۳۰ درخواست) در فایل `Building Management Pro - Master.postman_collection`
- 🗄️ اسکریپت جامع دیتابیس: `all_migrations.sql` (۳۲ جدول)
- 📚 مستندات کامل پروژه در پوشه `docs/`

---

## ✨ امکانات اصلی

### ۱. احراز هویت (JWT)
- ثبت‌نام، ورود، تازه‌سازی توکن (Refresh)، خروج، **ویرایش مشخصات** (`PUT /auth/me`) و **تغییر رمز عبور** (`PUT /auth/password`)
- توکن JWT با الگوریتم HS256 و انقضای ۱ ساعته (`JWT_EXPIRY`)
- ذخیره خودکار توکن در کالکشن Postman بعد از ورود

### ۲. مدیریت ساختمان
- CRUD کامل ساختمان (ایجاد، نمایش، ویرایش، حذف)
- **سلسله مراتب داینامیک**: هر ساختمان می‌تواند بلوک / طبقه / واحد / مشاعات را جداگانه فعال یا غیرفعال کند (`building_hierarchy_settings`)
- قابلیت White Label: `custom_name`، `theme_color`، `custom_logo_path`

### ۳. ساختار و سلسله مراتب
- بلوک‌ها (Blocks) — داینامیک
- طبقه‌ها (Floors) — متصل به بلوک یا مستقیم به ساختمان
- واحدها (Units) — با نوع (مسکونی/تجاری)، متراژ، مالک، مستاجر، وضعیت سکونت (مالک ساکن / مستاجر ساکن / خالی) و **قطعه پارکینگ/انباری اختصاصی**
- مشاعات (Common Areas) — پارکینگ، سالن، استخر و... با قابلیت رزرو

### ۴. اعضا و دعوت‌نامه‌ها
- عضویت در چند ساختمان همزمان با نقش‌های متفاوت (`manager`, `resident`, `tenant`, `accountant`)
- سیستم دعوت با **توکن یکتا و انقضای ۷ روزه** و نقش مشخص
- پذیرش دعوت و ثبت خودکار در اعضای ساختمان
- لیست اعضا با اطلاعات کاربر (JOIN)

### ۵. هزینه‌ها و مالی
- ثبت هزینه دوره‌ای (ماهانه/فصلی/سالانه) و یک‌باره
- **تقسیم داینامیک هزینه**: سهم ثابت (`fixed_share`)، بر اساس متراژ (`area`)، بر اساس تعداد ساکنان (`people_count`)
- جریان کامل پرداخت: `pending → upload_receipt → confirmed`
- آپلود رسید پرداخت (حداکثر ۵ مگابایت، MIME مجاز: jpeg/png/webp/pdf)
- تایید پرداخت توسط مدیر
- **جریمه دیرکرد داینامیک**: درصدی یا مبلغ ثابت + روز تاخیر

### ۶. تیکت‌ها و اعلانات
- تیکت با دسته‌بندی (فنی، مالی، مدیریتی، شکایت، پیشنهاد)، اولویت (low/normal/high/urgent) و قابلیت ارسال **ناشناس**
- تغییر وضعیت تیکت: `open → in_progress → resolved → closed → rejected`
- کامنت‌های داخلی (فقط مدیر) و خارجی
- اعلانات با نوع، ساختمان، وضعیت خوانده‌شده

### ۷. ماژول‌های حرفه‌ای (فاز ۶+) — ۱۱ ماژول
| ماژول | توضیح |
|---|---|
| رزرو مشاعات (Bookings) | رزرو سالن، پارکینگ، استخر |
| اطلاعیه‌ها (Announcements) | تابلو اعلانات با امکان پین |
| درخواست تعمیرات (Maintenance) | ثبت و پیگیری مشکلات فنی |
| نظرسنجی و رأی‌گیری (Votes) | رأی‌گیری برای تصمیمات ساختمان |
| مدیریت مهمان (Visitors) | ثبت مهمان و پلاک خودرو |
| قوانین و اسناد (Documents) | اساسنامه، قوانین، قراردادها |
| مصرف انرژی (Consumption) | ثبت قرائت آب/برق/گاز واحدها |
| سیستم اضطراری (Emergency) | شماره‌های اضطراری و هشدار سریع |
| جلسات ساختمان (Meetings) | برنامه‌ریزی و صورت‌جلسه |
| امتیازدهی و نظرات (Reviews) | امتیاز به خدمات مدیریت |
| یادآوری رویدادها (Reminders) | یادآوری خودکار جلسه‌ها و رزروها با اعلان درون‌اپ + پیامک (کران `scripts/reminders.php`) |

---

## 🛠 تکنولوژی‌ها

| بخش | تکنولوژی |
|---|---|
| زبان | PHP 8.3 (strict_types) |
| معماری | MVC سفارشی + PSR-4 Autoload |
| API | REST + JSON |
| احراز هویت | JWT (HS256) — کتابخانه `firebase/php-jwt` |
| دیتابیس | MySQL / MariaDB — PDO (Prepared Statements) |
| کش / Rate Limit | Redis — کتابخانه `predis/predis` |
| HTTP | `symfony/http-foundation` (اختیاری) |
| تست | PHPUnit ^11 (dev) |
| فرانت‌اند | PHP صفحات ریسپانسیو RTL فارسی (Vazirmatn) |

---

## 📁 ساختار پروژه

```
Building_Management/
├── public/
│   └── index.php              # Entry point REST API (Kernel)
├── config/
│   ├── app.php                # تنظیمات DB، JWT، Redis، Storage، محدودیت فایل
│   └── routes.php             # تعریف تمام مسیرهای REST
├── src/
│   ├── Core/                  # Kernel, Router, Request, Response, Container, Database, MiddlewarePipeline
│   ├── Http/
│   │   ├── Controllers/       # Auth, Building, Cost, Ticket, Notification, ExtraModules
│   │   └── Middleware/        # Auth (JWT), RateLimit, Cors, Cache
│   ├── Services/              # Building, User, Cost, Invitation, Ticket, Notification
│   ├── Repositories/          # 10 مخزن داده (PSR style)
│   ├── Models/                # 17 مدل (User, Building, Block, Floor, Unit, Cost, ...)
│   ├── Utilities/             # JwtHelper, Validator, CacheHelper, FileStorage
│   └── Exceptions/            # AppException, AuthException, ValidationException
├── database/
│   ├── migrations/            # 20 فایل Migration (001 تا 020)
│   └── seeds/
├── scripts/
│   └── migrator.php           # اجرای خودکار Migrationها
├── assets/
│   ├── css/style.css          # استایل استاندارد یکتای کل اپ (همه صفحات)
│   └── js/main.js             # اسکریپت مشترک همه صفحات
├── includes/                  # قالب‌های مشترک پنل وب
│   ├── api_helper.php         # اتصال به API + توابع کمکی فارسی
│   ├── header.php             # هدر استاندارد (head + هدر اپ + هشدار)
│   ├── footer.php             # فوتر استاندارد (ناوبری پایین + main.js)
│   ├── page_head.php / page_tail.php    # wrapper هدر/فوتر (سازگاری)
│   ├── dash_head.php / dash_tail.php    # wrapper هدر/فوتر (سازگاری)
│   └── app_styles.php         # shim قدیمی — لینک به style.css
├── docs/                      # proposal, final_decisions, phase_1_2, project_complete_summary
├── index.php                  # داشبورد وب (موبایل‌فرست، RTL)
├── auth.php                   # ورود/ثبت‌نام یکپارچه با موبایل و کد یک‌بارمصرف
├── login.php / register.php   # فقط ریدایرکت ۳۰۱ به auth.php (سازگاری)
├── profile.php / logout.php
├── building_add.php / building_view.php
├── all_migrations.sql         # اسکریپت جامع SQL (۳۲ جدول)
├── Building Management Pro - Master.postman_collection  # کالکشن Postman
├── .htaccess                  # Rewrite (LiteSpeed) + مسیردهی /api
└── composer.json
```

---

## 🚀 راه‌اندازی و نصب

### پیش‌نیازها
- PHP **8.3+** با افزونه‌های: `pdo`, `pdo_mysql`, `json`, `openssl`, `mbstring`, `curl`
- MySQL 8 / MariaDB 11
- Redis (برای کش و Rate Limit)
- Composer

### مراحل نصب

```bash
# ۱. کلون کردن پروژه
git clone <repository-url>
cd Building_Management

# ۲. نصب وابستگی‌ها
composer install
composer dump-autoload   # پس از افزودن کلاس‌های جدید

# ۳. تنظیمات محیطی (اجباری)
cp .env.example .env
openssl rand -base64 48        # خروجی را در JWT_SECRET بگذارید
#   سپس در .env این‌ها را پر کنید:
#     APP_ENV=production
#     JWT_SECRET=<کلید تولیدشده>
#     DB_PASSWORD=<رمز دیتابیس>
#     APP_URL=https://example.com/b

# ۴. ساخت دیتابیس و اجرای مایگریشن‌ها
composer migrate:status        # ببینید چه چیزی در انتظار است
composer migrate               # اجرای مایگریشن‌های اعمال‌نشده
#   یا به‌جای آن، اسکریپت جامع:
#     mysql -u <user> -p <db> < all_migrations.sql

# ۵. بررسی سلامت و آمادگی
composer health                # php scripts/healthcheck.php

# ۶. اجرای تست‌ها (بدون نیاز به دیتابیس)
composer test                  # php tests/Integration/run.php

# ۷. اجرای سرور API (بک‌اند)
composer serve                 # php -S 0.0.0.0:8000 -t public/

# ۸. اجرای فرانت‌اند (پنل وب) — در ترمینالی جدا
API_BASE_URL=http://localhost:8000/b/api php -S 0.0.0.0:8080
#   سپس http://localhost:8080/auth.php را باز کنید
```

> ⚠️ از نسخه فعلی، **رمز دیتابیس و کلید JWT دیگر در `config/app.php` نیستند**.
> اگر `.env` را نسازید، برنامه در محیط production عمداً بالا نمی‌آید و پیام صریح می‌دهد.

### نکته استقرار در ساب‌فولدر
پروژه برای اجرا در ساب‌فولدر (مثلاً `/b`) طراحی شده است:
- `.htaccess` درخواست‌های `api/*` را به `public/index.php` هدایت می‌کند.
- `public/index.php` پیشوند `/b` را از URL حذف می‌کند تا مسیرها استاندارد خوانده شوند.
- با XAMPP کافی است پروژه را در `htdocs/b` کپی کنید؛ سپس فرانت‌اند در
  `http://localhost/b/auth.php` و API در `http://localhost/b/api/...` در دسترس است.
- آدرس پیش‌فرض API در `includes/api_helper.php` سرور اصلی (`https://file.falnic.com/b/api`) است؛
  برای اجرای محلی از `API_BASE_URL` استفاده کنید (بدون دست‌زدن به فایل).
- فرانت‌اند (index.php و... ) از طریق `includes/api_helper.php` با `API_BASE_URL` به API متصل می‌شود.

---

## پیامک و ورود با موبایل

- نام‌کاربری هر کاربر **شماره موبایل** است (ثبت‌نام و ورود با موبایل + رمز عبور).
- پس از ثبت‌نام، **پیامک خوش‌آمد** و هنگام دعوت عضو، **پیامک حاوی لینک دعوت** ارسال می‌شود (ملی‌پیامک، متد `SendByBaseNumber`).
- اسکریپت یادآوری رویدادها (`scripts/reminders.php`) نیز **پیامک یادآوری جلسه/رزرو** ارسال می‌کند.
- نیاز به افزونه `php-soap` دارد؛ اگر نباشد، خطا فقط لاگ می‌شود و جریان ثبت‌نام/دعوت متوقف نمی‌شود.

| متغیر محیطی | پیش‌فرض | توضیح |
|---|---|---|
| `MELIPAYAMAK_USERNAME` | `9905367498` | نام‌کاربری پنل پیامک |
| `MELIPAYAMAK_PASSWORD` | `96R3Q` | رمز پنل پیامک |
| `MELIPAYAMAK_BODY_ID` | `530743` | شناسه بدنه (پترن) |
| `MELIPAYAMAK_REMINDER_BODY_ID` | — | پترن جداگانه پیامک یادآوری رویدادها (اختیاری؛ آرگومان‌ها به‌ترتیب: نام، عنوان رویداد، زمان، نام ساختمان). اگر تنظیم نشود، متن کامل با پترن پیش‌فرض ارسال می‌شود |
| `APP_URL` | `https://file.falnic.com/b` | آدرس پایه اپ برای ساخت لینک دعوت در پیامک |

### شارژ ثابت ماهیانه (خودکار)

- در صفحه ساختمان (ساخت/ویرایش) یا صفحه مالی، مبلغ **شارژ ثابت ماهیانه** را فعال و ذخیره کنید.
- با هر بار مشاهده صفحه مالی، شارژ ماه جاری به‌صورت خودکار ساخته و به بدهکاری‌ها اضافه می‌شود.
- برای اطمینان (مثلاً سرور کرون)، ماهی یک‌بار اجرا کنید:

```bash
php scripts/monthly_charges.php
# کرون ماهانه: 0 1 1 * * /usr/bin/php /path/to/scripts/monthly_charges.php
```

### یادآوری رویدادها (خودکار)

- اسکریپت `scripts/reminders.php` جلسه‌ها و رزروهایی که در پنجره یادآوری
  (پیش‌فرض ۲۴ ساعت آینده) قرار دارند پیدا می‌کند و برایشان:
  - **اعلان درون‌اپ** می‌سازد (جلسه → همه اعضای فعال ساختمان، رزرو → خود رزروکننده)
  - **پیامک یادآوری** ارسال می‌کند (به شماره‌های معتبر)
- ارسال‌ها در جدول `event_reminders` (مایگریشن 028) با کلید یکتا ثبت می‌شوند؛
  اجرای مکرر کران باعث ارسال تکراری **نمی‌شود**.
- زمان رویداد در اعلان و پیامک به‌صورت **شمسی** نمایش داده می‌شود.

```bash
php scripts/reminders.php
# کرون ساعتی (پیشنهادی):
# 0 * * * * /usr/bin/php /path/to/scripts/reminders.php >> /var/log/bm_reminders.log 2>&1
```

| متغیر محیطی | پیش‌فرض | توضیح |
|---|---|---|
| `REMINDER_WINDOW_HOURS` | `24` | پنجره یادآوری بر حسب ساعت |
| `MELIPAYAMAK_REMINDER_BODY_ID` | — | پترن جداگانه پیامک یادآوری در ملی‌پیامک (اختیاری؛ آرگومان‌ها: نام، عنوان رویداد، زمان، نام ساختمان) |

### اجرای مایگریت‌های جدید

```bash
php scripts/migrator.php   # شامل 023 (ورود با موبایل) و 024 (مشخصات ساختمان + نام دعوت‌شونده)
```

## ⚙️ تنظیمات

### متغیرهای محیطی (فایل `.env`)

از روی `.env.example` بسازید. مقادیر حساس **دیگر در کد نیستند**.

| متغیر | پیش‌فرض | اجباری در production | توضیح |
|---|---|---|---|
| `APP_ENV` | `production` | — | `development` / `testing` / `production` |
| `APP_URL` | آدرس سرور اصلی | ✅ | برای ساخت لینک دعوت در پیامک |
| `JWT_SECRET` | — | ✅ | کلید امضای JWT، حداقل ۳۲ کاراکتر |
| `DB_DSN` | `mysql:host=localhost;...` | — | رشته اتصال PDO |
| `DB_USERNAME` | `file_b` | — | کاربر دیتابیس |
| `DB_PASSWORD` | — | ✅ | رمز دیتابیس |
| `SMS_USERNAME` / `SMS_PASSWORD` / `SMS_BODY_ID` | — | — | پنل ملی‌پیامک |
| `APP_LOG_DIR` | `storage/logs` | — | مسیر فایل‌های لاگ |
| `APP_LOG_LEVEL` | `info` | — | `debug`/`info`/`warning`/`error`/`critical` |
| `OTP_DEBUG` | خاموش | ❌ هرگز | با `1` کد ورود در پاسخ API برمی‌گردد (فقط توسعه) |
| `API_INSECURE_SSL` | خاموش | ❌ هرگز | غیرفعال‌کردن بررسی SSL (فقط توسعه محلی) |

> **پیش‌فرض امن:** اگر `APP_ENV` تنظیم نشود، `production` فرض می‌شود.
> در این حالت نبودِ `JWT_SECRET` یا `DB_PASSWORD` باعث **خطای صریح** می‌شود
> تا برنامه با تنظیمات ناامن بالا نیاید.

### ثابت‌های کد (`config/app.php`)

| ثابت | مقدار | توضیح |
|---|---|---|
| `APP_NAME` | Building Management Pro | نام اپلیکیشن |
| `APP_VERSION` | 1.0.0 | نسخه |
| `JWT_ALGO` | HS256 | الگوریتم امضا |
| `JWT_EXPIRY` | 3600 | انقضای توکن (ثانیه) |
| `JWT_REFRESH_EXPIRY` | 604800 | انقضای رفرش (۷ روز) |
| `REDIS_HOST/PORT/DB` | 127.0.0.1:6379/0 | Redis |
| `MAX_FILE_SIZE` | 5MB | حداکثر حجم فایل |
| `ALLOWED_MIME_TYPES` | jpeg/png/webp/pdf | MIME مجاز |

> 🚨 **هشدار مهم درباره اسرار قدیمی:**
> رمز دیتابیس و `JWT_SECRET` قبلی **در تاریخچه‌ی git باقی مانده‌اند**.
> حذفشان از کد کافی نیست — حتماً:
> 1. رمز دیتابیس را روی هاست **عوض کنید**،
> 2. یک `JWT_SECRET` تازه بسازید (`openssl rand -base64 48`)،
> 3. هر دو را در `.env` قرار دهید (که در `.gitignore` است).
> تا وقتی این کار انجام نشود، کلید قدیمی همچنان قابل سوءاستفاده است.

---

## 🔐 امنیت

| مکانیزم | توضیح |
|---|---|
| **CSRF** | همه درخواست‌های POST به‌صورت خودکار بررسی می‌شوند؛ بدون توکن معتبر پاسخ ۴۱۹. در فرم‌های جدید `<?= csrf_field() ?>` را اضافه کنید. |
| **محدودیت نرخ ورود** | ۵ تلاش ناموفق در ۱۵ دقیقه، سپس پاسخ ۴۲۹. کلید ترکیبی شماره+IP و ذخیره‌شده به‌صورت SHA-256. |
| **کد یک‌بارمصرف** | انقضای ۲ دقیقه، فاصله ارسال مجدد ۶۰ ثانیه، سقف ۵ تلاش و ۶ کد در ساعت. کدها هش می‌شوند. |
| **رمز عبور** | `password_hash()` با الگوریتم پیش‌فرض PHP. |
| **SSL** | بررسی گواهی به‌صورت پیش‌فرض فعال است. |
| **لاگ** | داده‌های حساس (رمز، توکن، کد) پیش از ثبت با `[redacted]` جایگزین می‌شوند. |

---

## 🗄️ دیتابیس (۳۲ جدول)

Migrationها از `001` تا `020` در `database/migrations/` و نسخه SQL یکپارچه در `all_migrations.sql`:

| # | جدول | توضیح |
|---|---|---|
| 001 | `users` | کاربران (ایمیل یکتا، رمز هش‌شده، soft delete) |
| 002 | `buildings` | ساختمان (نام، آدرس، created_by، white label) |
| 003 | `building_members` | عضویت کاربر در ساختمان با نقش (manager/resident/tenant/accountant) |
| 004 | `building_hierarchy_settings` | تنظیم داینامیک بلوک/طبقه/واحد/مشاعات |
| 005 | `blocks` | بلوک‌ها |
| 006 | `floors` | طبقه‌ها (متصل به بلوک یا ساختمان) |
| 007 | `units` | واحدها (متراژ، نوع، مالک، مستاجر، مالک ساکن) |
| 008 | `common_areas` | مشاعات (پارکینگ، سالن، استخر...) |
| 009 | `costs` | هزینه‌ها (نوع، تقسیم، دوره‌ای/یک‌باره) |
| 010 | `cost_payments` | پرداخت‌ها (pending → upload_receipt → confirmed) |
| 011 | `receipts` | رسیدهای واریز |
| 012 | `penalty_settings` | تنظیم جریمه دیرکرد (درصد/ثابت + روز تاخیر) |
| 013 | `penalties` | جریمه‌های اعمال‌شده |
| 014 | `invitations` | دعوت‌نامه‌ها (توکن یکتا، انقضا ۷ روز، unit_id برای اتصال خودکار مالک/مستاجر) |
| 015 | `tickets` | تیکت‌ها (دسته، اولویت، ناشناس، وضعیت) |
| 016 | `ticket_comments` | کامنت‌های تیکت (داخلی/خارجی) |
| 017 | `notifications` | اعلانات |
| 018 | `bookings`, `announcements`, `maintenance_requests` | ماژول‌های فاز ۶ (بخش ۱) |
| 019 | `votes`, `vote_options`, `vote_results`, `visitors`, `documents` | ماژول‌های فاز ۶ (بخش ۲) |
| 020 | `consumption_readings`, `emergency_contacts`, `emergency_alerts`, `meetings`, `meeting_minutes`, `review_categories`, `reviews` | ماژول‌های فاز ۶ (بخش ۳) |
| 021 | `units.owner_resident` | ستون مالک ساکن (تفکیک سناریوهای سکونت) |
| 022 | `invitations.unit_id` | ستون واحد هدف دعوت‌نامه |
| 023 | `users.phone` | ورود با شماره موبایل (اینکس یکتا) |
| 024 | مشخصات ساختمان و نام دعوت‌شونده | فیلدهای تکمیلی ساختمان + `invitations.invited_name` |
| 025 | حالت‌های شارژ | `fixed` / `per_person` / `custom` + تعداد نفرات و شارژ اختصاصی واحد |
| 026 | `otp_codes` | کدهای یک‌بارمصرف ورود (هش‌شده، با انقضا و سقف تلاش) |
| 027 | `rate_limits` | محدودیت نرخ ورود (کلید SHA-256) |
| 028 | `event_reminders` | ردیابی یادآوری رویدادها (اعلان/پیامک) با کلید یکتای ضدتکرار |
| 029 | `units.parking_no`, `units.storage_no` | قطعه پارکینگ و قطعه انباری اختصاصی هر واحد |
| 030 | `costs.target_unit_ids`, `costs.issued_at`, `cost_payments.share_amount` | صدور هزینه برای مخاطبان انتخابی (ساکنین/مالکین/مستأجرین/واحدهای خاص) |
| 031 | `documents.stored_name`, `mime_type`, `file_size`, `is_visible_to_members`, `updated_at` | آپلود امن اسناد ساختمان با نام تصادفی، دسته‌بندی و کنترل رویت اعضا |
| 032 | `cost_payments.unit_id`, `cost_payments.reject_reason`, جدول `audit_logs` | حسابداری بدهکار/طلبکار هر واحد، چرخه تأیید/رد پرداخت با دلیل، و لاگ ممیزی اقدامات |

ویژگی‌های دیتابیس: **Foreign Key** با `ON DELETE CASCADE / SET NULL`، ایندکس‌های ترکیبی، `utf8mb4_unicode_ci`، ستون‌های JSON برای تنظیمات داینامیک.

---

## 🔌 مستندات API

- **Base URL (محیط توسعه):** `https://file.falnic.com/b/api`
- **احراز هویت:** هدر `Authorization: Bearer <token>` (به‌جز register/login/refresh)
- **فرمت پاسخ:** JSON با ساختار `{ "success": bool, "message"?: string, "data"?: ... }`
- **کالکشن Postman:** فایل `Building Management Pro - Master.postman_collection` — شامل ۱۳۰ درخواست در ۷ پوشه، متغیر `base_url`، ذخیره خودکار توکن بعد از Login و هدرهای خودکار `Accept` و `Content-Type: application/json`

### ۱. احراز هویت (Auth)

| متد | مسیر | توضیح | بدنه (نمونه) |
|---|---|---|---|
| POST | `/auth/register` | ثبت‌نام | `{ "name", "email", "password", "password_confirmation" }` |
| POST | `/auth/login` | ورود + دریافت خودکار توکن | `{ "email", "password" }` |
| POST | `/auth/refresh` | تازه‌سازی توکن | `{ "token" }` |
| POST | `/auth/logout` | خروج | — |

### ۲. مدیریت ساختمان (Buildings)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/buildings` | لیست ساختمان‌های کاربر |
| POST | `/buildings` | ایجاد ساختمان — `{ "name", "address" }` |
| GET | `/buildings/{id}` | نمایش ساختمان |
| PUT | `/buildings/{id}` | ویرایش — `{ "name" }` |
| DELETE | `/buildings/{id}` | حذف |

### ۳. ساختار و سلسله مراتب (Hierarchy)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/buildings/{id}/hierarchy/settings` | تنظیمات داینامیک |
| PUT | `/buildings/{id}/hierarchy/settings` | آپدیت تنظیمات — `{ "has_blocks", "has_floors", ... }` |
| GET/POST | `/buildings/{id}/blocks` | لیست / افزودن بلوک — `{ "name" }` |
| GET/POST | `/buildings/{id}/floors` | لیست / افزودن طبقه — `{ "floor_number", "block_id" }` |
| GET/POST | `/buildings/{id}/units` | لیست / افزودن واحد — `{ "unit_number", "type", "area", "block_id", "floor_id", "owner_user_id", "tenant_user_id", "owner_resident", "parking_no", "storage_no" }` |
| PUT/DELETE | `/units/{id}` | ویرایش / حذف واحد (با مالک/مستاجر/ساکن) |
| GET/POST | `/buildings/{id}/common-areas` | لیست / افزودن مشاعات — `{ "name", "is_bookable" }` |

### ۴. اعضا و دعوت‌نامه‌ها (Members)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/buildings/{id}/members` | لیست اعضای ساختمان (با واحدهای مرتبط و رابطه: owner / owner_resident / tenant) |
| POST | `/buildings/{id}/invitations` | ایجاد دعوت‌نامه — `{ "invited_phone", "invited_email", "role", "unit_id" }` |
| POST | `/invitations/accept` | پذیرش دعوت — `{ "token" }` — اگر دعوت‌نامه unit_id داشته باشد، کاربر خودکار مالک/مستاجر آن واحد می‌شود |
| POST | `/invitations/{id}/resend` | ارسال مجدد پیامک دعوت — `{ "building_id" }` |
| DELETE | `/invitations/{id}` | لغو دعوت‌نامه (فقط مدیر، فقط دعوت‌های در انتظار پذیرش) — لینک دعوت باطل می‌شود |

### ۵. هزینه‌ها و مالی (Costs & Payments)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/costs?building_id=` | لیست هزینه‌های ساختمان |
| GET | `/costs/summary?building_id=` | خلاصه مالی (مجموع، وصولی، مانده، درصد) |
| POST | `/costs` | ثبت هزینه — `{ "building_id", "title", "amount", "cost_type", "division_method", "target_audience", "target_unit_ids", "auto_issue" }` |
| POST | `/costs/{id}/issue` | صدور هزینه برای مخاطبان انتخابی (ساخت ردیف پرداخت + اعلان) — فقط مدیر |
| GET | `/payments?building_id=` | لیست پرداخت‌های ساختمان (با نام هزینه و کاربر) |
| POST | `/payments/submit` | ارسال درخواست پرداخت — `{ "cost_id", "amount_paid", "notes" }` |
| POST | `/payments/{id}/upload-receipt` | آپلود رسید (multipart/form-data — فیلد `receipt`) |
| POST | `/payments/{id}/confirm` | تایید پرداخت توسط مدیر — `{ "status": "confirmed" }` |
| POST | `/penalty-settings` | تنظیم جریمه — `{ "building_id", "type", "amount", "delay_days" }` |
| GET | `/penalty-settings?building_id=` | لیست تنظیم‌های جریمه ساختمان (برای همه اعضا) |
| PUT | `/penalty-settings/{id}` | ویرایش تنظیم جریمه — `{ "type", "amount", "delay_days", "is_active" }` — فقط مدیر |
| DELETE | `/penalty-settings/{id}` | حذف تنظیم جریمه — فقط مدیر |

### ۶. تیکت‌ها و اعلانات (Tickets & Notifications)

| متد | مسیر | توضیح |
|---|---|---|
| GET/POST | `/tickets` | لیست / ثبت تیکت — `{ "building_id", "title", "description", "category", "priority" }` |
| GET | `/tickets/{id}` | نمایش تیکت |
| PUT | `/tickets/{id}` | ویرایش تیکت — `{ "title", "description", "category", "priority" }` — فقط صاحب تیکت یا مدیر ساختمان |
| DELETE | `/tickets/{id}` | حذف تیکت (با کامنت‌ها) — صاحب تیکت فقط در وضعیت باز/ردشده، مدیر همیشه |
| GET | `/tickets/{id}/comments` | لیست کامنت‌های تیکت |
| PUT | `/tickets/{id}/status` | تغییر وضعیت — `{ "status": "in_progress" }` |
| POST | `/tickets/{id}/comments` | ثبت کامنت — `{ "comment", "is_internal" }` |
| GET/POST | `/notifications` | لیست / ایجاد اعلان — `{ "user_id", "title", "message" }` |
| POST | `/notifications/{id}/read` | علامت‌گذاری خوانده‌شده |

### ۶.۵. اطلاعات کاربر و مالی

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/auth/me` | اطلاعات کاربر لاگین‌شده |
| PUT | `/auth/me` | ویرایش مشخصات (نام و شماره موبایل) — `{ "name", "phone" }` |
| GET | `/costs?building_id={id}` | لیست هزینه‌های ساختمان |
| GET | `/costs/summary?building_id={id}` | خلاصه مالی ساختمان (کل هزینه‌ها، وصولی، درصد وصول، تعداد) |

### ۷. ماژول‌های حرفه‌ای (Phase 6+) — پیاده‌سازی کامل

> همه ماژول‌ها با الگوی Model → Repository → Service → Controller پیاده‌سازی شده‌اند؛ عملیات فقط برای **اعضای فعال همان ساختمان** مجاز است.

| متد | مسیر | بدنه (نمونه) |
|---|---|---|
| GET/POST | `/bookings` | `{ "building_id", "common_area_id", "date", "start_time", "end_time" }` |
| PUT | `/bookings/{id}/status` | `{ "status": "pending" | "confirmed" | "cancelled" | "completed" }` |
| DELETE | `/bookings/{id}` | حذف رزرو |
| GET/POST | `/announcements` | `{ "building_id", "title", "content", "is_pinned" }` |
| DELETE | `/announcements/{id}` | حذف اطلاعیه |
| GET/POST | `/maintenance` | `{ "building_id", "title" یا "issue", "description" }` |
| PUT | `/maintenance/{id}/status` | `{ "status": "pending" | "in_progress" | "resolved" | "closed" }` |
| DELETE | `/maintenance/{id}` | حذف درخواست تعمیر |
| GET/POST | `/votes` | `{ "building_id", "title", "description", "end_date", "options": ["گزینه ۱", "گزینه ۲"] }` |
| POST | `/votes/{id}/options` | `{ "options": ["گزینه جدید ۱", ...] }` — افزودن گزینه به رأی‌گیری باز |
| POST | `/votes/{id}/vote` | `{ "option_id": 1 }` — ثبت رأی (هر کاربر یک بار) |
| GET | `/votes/{id}/results` | نتیجه: تعداد/درصد آراء هر گزینه + وضعیت رأی کاربر |
| PUT | `/votes/{id}/status` | `{ "status": "active" | "closed" }` — بستن/بازکردن رأی‌گیری |
| DELETE | `/votes/{id}` | حذف رأی‌گیری و گزینه‌ها/آراء آن (Cascade) |
| GET/POST | `/visitors` | `{ "building_id", "visitor_name", "visitor_car_plate", "visit_date" }` |
| PUT | `/visitors/{id}/checkout` | `{ "status": "exited" }` — ثبت خروج (به‌همراه زمان خروج) |
| DELETE | `/visitors/{id}` | حذف مهمان |
| GET/POST | `/documents` | `{ "building_id", "title", "file_path", "document_type" }` |
| DELETE | `/documents/{id}` | حذف سند |
| GET/POST | `/consumption` | `{ "building_id", "unit_id", "type", "amount", "reading_date" }` |
| DELETE | `/consumption/{id}` | حذف قرائت |
| GET/POST | `/emergency-contacts` | `{ "building_id", "name", "phone", "role", "email" }` |
| GET/POST | `/emergency-alerts` | `{ "building_id", "alert_type", "message" }` — هشدار اضطراری (آتش/امنیت/پزشکی/...) |
| DELETE | `/emergency-contacts/{id}` | حذف مخاطب اضطراری |
| GET/POST | `/meetings` | `{ "building_id", "title", "description", "meeting_date", "location" }` |
| PUT | `/meetings/{id}/status` | `{ "status": "scheduled" | "completed" | "cancelled" }` |
| POST/GET | `/meetings/{id}/minutes` | ثبت/لیست صورت‌جلسه |
| DELETE | `/meetings/{id}` | حذف جلسه |
| GET/POST | `/reviews` | `{ "building_id", "rating" (1-5), "comment" }` |
| DELETE | `/reviews/{id}` | حذف نظر |
| GET/POST | `/review-categories` | لیست/ایجاد دسته‌بندی نظرات |

> نکته: برای لیست‌ها پارامتر کوئری `building_id` اجباری است، نمونه: `GET /announcements?building_id=2`

---

## 🌐 پنل وب (فرانت‌اند فارسی)

| صفحه | فایل | توضیح |
|---|---|---|
| ورود | `login.php` | ورود با ایمیل/رمز و ذخیره توکن در Session |
| ثبت‌نام | `register.php` | ساخت حساب کاربری جدید |
| داشبورد | `index.php` | داشبورد موبایل‌فرست RTL — لیست ساختمان‌ها، اعلانات، دسترسی سریع (`dashboard.php` نام قدیمی → هدایت به `index.php`) |
| پروفایل | `profile.php` / `profile_edit.php` / `change_password.php` | مشاهده پروفایل، ویرایش مشخصات و تغییر رمز عبور |
| ثبت ساختمان | `building_add.php` | افزودن ساختمان جدید |
| مشاهده ساختمان | `building_view.php` | جزئیات ساختمان + نمای گرافیکی بلوک/طبقه/واحد با پاپ‌آپ اطلاعات و اقدامات واحد |
| خروج | `logout.php` | پاک‌سازی Session |
| بلوک‌ها / طبقات / واحدها / مشاعات | `blocks.php` / `floors.php` / `units.php` / `common_areas.php` | مدیریت ساختار مجتمع (لیست + ثبت) |
| مالی و شارژ | `costs.php` | خلاصه مالی، لیست هزینه‌ها و پرداخت‌ها، ثبت هزینه و تأیید پرداخت |
| تیکت‌ها | `tickets.php` | لیست و ثبت تیکت |
| جزئیات تیکت | `ticket_view.php` | نمایش تیکت، تغییر وضعیت و گفتگو (کامنت) |
| اعضا | `members.php` | لیست اعضا + ارسال دعوت‌نامه |
| اطلاعیه‌ها / تعمیرات / رزرو / رأی‌گیری | `announcements.php` / `maintenance.php` / `bookings.php` / `votes.php` | ماژول‌های اطلاع‌رسانی و مشارکت |
| مهمان‌ها / مصرف انرژی / اضطراری / جلسات / نظرات | `visitors.php` / `consumption.php` / `emergency_contacts.php` / `meetings.php` / `reviews.php` | ماژول‌های حرفه‌ای فاز ۶ |
| اسناد ساختمان | `documents.php` + `document_download.php` | آپلود امن فایل با دسته‌بندی (۸ دسته)، نمایش نام و زمان شمسی، کنترل رویت اعضا/فقط مدیران، ویرایش و حذف فقط مدیر، دانلود از مسیر معتبرسازی‌شده بدون دسترسی مستقیم به فایل |
| تقویم / گزارش‌ها | `calendar.php` / `reports.php` | **تقویم شمسی** رویدادها (جلسات + رزروها) با جزئیات هر روز و فهرست رویدادهای پیشِ رو؛ نمای کلی عملکرد |
| ویرایش/حذف ساختمان | `building_edit.php` / `building_delete.php` | ویرایش اطلاعات (PUT) و حذف ساختمان (DELETE) |
| پذیرش دعوتنامه | `invite.php?token=...` | مشاهده اطلاعات دعوت (نقش/واحد) و پذیرش (`POST /invitations/accept`) |
| اعلانات | `notifications.php` | لیست اعلانات کاربر + علامت‌گذاری خوانده‌شده |

> فرانت‌اند از طریق `includes/api_helper.php` با تابع `callAPI()` به REST API متصل می‌شود (cURL + Bearer Token).
>
> **ساختار استاندارد صفحات:** همه صفحات از قالب‌های مشترک استفاده می‌کنند — `includes/header.php` (هدر: head کامل + هدر اپ + پیام هشدار)، `includes/footer.php` (ناوبری پایین + لود `assets/js/main.js`) و استایل یکتای `assets/css/style.css` که در تمام صفحات لود می‌شود. صفحات ورود/ثبت‌نام مستقل هستند اما همان `style.css` و `main.js` را لود می‌کنند. (`page_head/page_tail` و `dash_head/dash_tail` برای سازگاری، wrapper همین قالب‌ها هستند.)
>
> صفحات ماژولی که `page_head.php`/`page_tail.php` را صدا می‌زنند، به‌طور خودکار از همین هدر/فوتر استاندارد تیره‌ی اپ استفاده می‌کنند.

---

## 🔐 امنیت لایه API

- ✅ **JWT HS256** با انقضای ۱ ساعته و Refresh
- ✅ **Rate Limiting** بر اساس IP + مسیر (Redis) — `RateLimitMiddleware`
- ✅ **CORS** کامل — `CorsMiddleware`
- ✅ **Prepared Statements** (PDO) — جلوگیری از SQL Injection
- ✅ **اعتبارسنجی ورودی** — `Validator` (required, email, min و...)
- ✅ **اعتبارسنجی فایل آپلود** — محدودیت ۵ مگابایت + MIME مجاز (jpeg/png/webp/pdf)
- ✅ **Soft Delete** در جداول حساس (`deleted_at`)
- ✅ **Cache** برای درخواست‌های GET — `CacheMiddleware` (کلید کش شامل `user_id` است تا داده‌ی کاربران بین آن‌ها رد و بدل نشود)
- ✅ هدرهای امنیتی: `X-Frame-Options: DENY`، `X-Content-Type-Options: nosniff`، `Referrer-Policy: strict-origin-when-cross-origin`
- ✅ اسرار **فقط** از متغیر محیطی خوانده می‌شوند: `DB_DSN` / `DB_USERNAME` / `DB_PASSWORD` / `JWT_SECRET` — بدون هیچ مقدار پیش‌فرضی در کد

---

## 📚 مستندات پروژه

| فایل | محتوا |
|---|---|
| `docs/proposal.md` | پروپوزال اولیه، معماری پیشنهادی، سوالات کلیدی و نقشه راه |
| `docs/final_decisions.md` | تصمیمات نهایی ساختار (تایید شده) — سلسله مراتب، نقش‌ها، پرداخت، امنیت |
| `docs/phase_1_2_complete.md` | گزارش تکمیل فاز ۱ و ۲ — هسته، ساختمان، سلسله مراتب |
| `docs/project_complete_summary.md` | خلاصه کامل فازهای ۱ تا ۵ + فازهای باقی‌مانده |
| `DEPLOYMENT.md` | **راهنمای گام‌به‌گام استقرار روی سرور** |
| `tests/README.md` | راهنمای تست‌ها، لاگ‌گیری و امنیت |
| `ci/README.md` | فعال‌سازی یکپارچگی پیوسته (گیت‌هاب اکشنز) |

---

## 🧪 تست و ابزارها

```bash
composer test            # ۴۳۳ تست یکپارچه (بدون نیاز به دیتابیس یا افزونه خاص)
composer test:unit       # تست‌های قدیمی PHPUnit (tests/Unit)
composer health          # بررسی سلامت و آمادگی استقرار
composer migrate:status  # نمایش مایگریشن‌های در انتظار
composer migrate         # اجرای مایگریشن‌های اعمال‌نشده
composer verify          # بررسی اتصال routes/controllers/services/models/schema
composer serve           # سرور توسعه روی پورت 8000
```

تست‌ها یک پایگاه‌داده **SQLite در حافظه** می‌سازند و آن را به `App\Core\Database`
تزریق می‌کنند، بنابراین سرویس‌های واقعی بدون نیاز به MySQL اجرا می‌شوند.
جزئیات در `tests/README.md`.

---

## 🗺 وضعیت پروژه

| فاز | وضعیت |
|---|---|
| فاز ۱ — هسته (Kernel, Router, Middleware, JWT) | ✅ تکمیل |
| فاز ۲ — ساختمان و سلسله مراتب داینامیک | ✅ تکمیل |
| فاز ۳ — هزینه‌ها و پرداخت‌ها | ✅ تکمیل |
| فاز ۴ — تیکت‌ها | ✅ تکمیل |
| فاز ۵ — دعوت‌نامه‌ها و اعلانات | ✅ تکمیل |
| فاز ۶ — ماژول‌های حرفه‌ای (۱۰ ماژول) | ✅ تکمیل — GET/POST + تغییر وضعیت (PUT) + حذف (DELETE) + رأی‌گیری کامل با گزینه/رأی/نتیجه |
| ورود یکپارچه با موبایل و کد یک‌بارمصرف | ✅ تکمیل |
| رابط مودال‌محور + ویرایش کامل + نقش‌ها + سه حالت شارژ | ✅ تکمیل |
| لاگ‌گیر مرکزی و ۴۳۳ تست یکپارچه | ✅ تکمیل |
| تقویم شمسی رویدادها + یادآوری خودکار (اعلان + پیامک) | ✅ تکمیل |
| هزینه موردی با انتخاب مخاطب (ساکنین/مالکین/مستأجرین/واحدهای خاص) و صدور سهم | ✅ تکمیل |
| نمای گرافیکی ساختمان (بلوک ← طبقه ← واحد) با پاپ‌آپ اطلاعات و اقدامات واحد | ✅ تکمیل |
| اسناد ساختمان با آپلود امن فایل، دسته‌بندی، کنترل رویت اعضا و دانلود معتبرسازی‌شده | ✅ تکمیل |
| چرخه کامل پرداخت: آپلود رسید ← تأیید یا ردِ مدیر با دلیل ← نشست به حساب واحد (بدهکار/طلبکار) | ✅ تکمیل |
| سخت‌سازی رأی‌گیری و نظرات: ایجاد/بستن فقط مدیر، جلوگیری رأی پیش از شروع، مخفی‌بودن نتایج تا پس از رأی، ویرایش نظر فقط مالک | ✅ تکمیل |
| لاگ ممیزی اقدامات هر کاربر (ورود، ساختمان، واحدها، هزینه‌ها، پرداخت‌ها، تیکت‌ها، اسناد) + صفحه «لاگ اقدامات» مدیر | ✅ تکمیل |
| اپلیکیشن وب پیش‌رونده (PWA) با بنر نصب هوشمند (رد = یادآوری یک‌هفته‌ای، نصب = مخفی همیشگی) | ✅ تکمیل |
| سخت‌سازی امنیتی (اسرار، CSRF، محدودیت نرخ) | ✅ تکمیل |
| تست و بهینه‌سازی (PHPStan, OpenAPI) | 🟡 تست‌ها کامل؛ PHPStan و OpenAPI باقی‌مانده |
| استقرار production (OPcache, .env, Backup) | 🟡 `.env` و healthcheck آماده؛ OPcache و Backup باقی‌مانده |

---

## 📄 لایسنس

MIT
