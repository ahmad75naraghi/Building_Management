# Building_Management
# 🏢 Building Management Pro — Professional Building Management System

> **نسخه PHP:** 8.3 | **معماری:** Pure PHP (Custom MVC) | **الگو:** REST API + JWT + Single Schema | **وب‌سرور:** LiteSpeed
>
> **شاخه فعال:** `arena/019ffbd3-building-management` | **وضعیت:** کامل و آماده فروش

---

## 📋 فهرست محتوا

1. [معرفی پروژه](#معرفی-پروژه)
2. [ویژگی‌های کلیدی و فروش](#ویژگیهای-کلیدی-و-فروش)
3. [معماری فنی و استراکچر](#معماری-فنی-و-استراکچر)
4. [فازهای پیاده‌سازی شده](#فازهای-پیادهسازی-شده)
5. [ساختار دیتابیس (۲۰ Migration)](#ساختار-دیتابیس-۲۰-migration)
6. [نصب و راه‌اندازی](#نصب-و-راهاندازی)
7. [پیکربندی پروژه](#پیکربندی-پروژه)
8. [نحوه کار با REST API](#نحوه-کار-با-rest-api)
9. [سیستم کش و عملکرد](#سیستم-کش-و-عملکرد)
10. [امنیت حرفه‌ای](#امنیت-حرفهای)
11. [ذخیره‌سازی فایل‌ها](#ذخیرهسازی-فایلها)
12. [مستندات فنی پروژه](#مستندات-فنی-پروژه)
13. [راهنمای توسعه‌دهنده](#راهنمای-توسعهدهنده)
14. [مجوز و کپی‌رایت](#مجوز-و-کپیرایت)

---

## معرفی پروژه

**Building Management Pro** یک سیستم مدیریت ساختمان کاملاً حرفه‌ای، قابل فروش و با پرفورمنس عالی است که از صفر با **PHP خالص (Pure PHP 8.3)** نوشته شده است. این پروژه بدون وابستگی به Laravel یا Symfony، بر پایه استانداردهای **PSR-4**، **PSR-7** و **PSR-12** ساخته شده و از **Composer** برای مدیریت وابستگی‌ها استفاده می‌کند.

این پروژه بر اساس یک **پروپوزال دقیق** با ۲۸ سوال کلیدی و رسیدن به استراکچر نهایی طراحی شده است. تمام تصمیمات در فایل `docs/final_decisions.md` ثبت شده و تمام فازها در مستندات `docs/project_complete_summary.md` خلاصه شده‌اند.

---

## ویژگی‌های کلیدی و فروش

### 🏗️ معماری حرفه‌ای و قابل فروش
- **Pure PHP 8.3** بدون فریم‌ورک سنگین (سریع، سبک، قابل شخصی‌سازی کامل)
- **MVC سفارشی** با جداسازی دقیق Controller، Service، Repository و Model
- **REST API کامل** از روز اول (تمام ماژول‌ها API دارند)
- **JWT Authentication** با Refresh Token و Blacklist در Redis
- **Single Database / Single Schema** با بهینه‌سازی دقیق Index و Foreign Key
- **Multi-Tenancy واقعی** — هر ساختمان داده کاملاً ایزوله دارد (`WHERE building_id`)

### 🏢 مدیریت ساختمان داینامیک
- **سلسله مراتب کاملاً قابل تنظیم** (`building_hierarchy_settings` با JSON):
  - فعال/غیرفعال کردن بلوک، طبقه، واحد و مشاعات به صورت مستقل
  - یک ساختمان می‌تواند فقط "واحد" داشته باشد بدون بلوک و طبقه
- **مشاعات** (`common_areas`) با قابلیت رزرو (`bookable`)
- **واحد تجاری و مسکونی** (`type` در جدول `units`)

### 👥 کاربران و نقش‌ها
- کاربر می‌تواند در **چند ساختمان همزمان** با **نقش‌های متفاوت** عضو باشد (`building_members`)
- نقش‌های پایه: `manager` (مدیر)، `resident` (ساکن)، `tenant` (مستاجر)، `accountant` (حسابدار)
- امکان سفارشی‌سازی نقش‌ها در دعوت (`invitations` با `role` مشخص)
- **انتقال مدیریت** با تأیید و ثبت در `building_members`

### 💰 سیستم هزینه و پرداخت حرفه‌ای
- **هزینه دوره‌ای** (`periodic`) و **هزینه یک‌باره** (`one_time`)
- **تقسیم هزینه داینامیک** بر اساس:
  - سهم ثابت (`fixed_share`)
  - متراژ (`area`)
  - تعداد ساکنان (`people_count`)
- **وضعیت پرداخت**: `pending` → `upload_receipt` → `confirmed` (توسط مدیر)
- **رسید پرداخت** با تنظیم دسترسی (`public` / `private`) و محدودیت حجم ۵ مگابایت
- **جریمه دیرکرد داینامیک** (`penalty_settings`):
  - نوع: درصد (`percentage`) یا مبلغ ثابت (`fixed_amount`)
  - روز تأخیر قابل تنظیم (`delay_days`)
  - اعمال خودکار پس از تأیید پرداخت (`penalties`)
- **تخفیف پرداخت زودهنگام** (قابل تنظیم در `penalty_settings` یا منطق سرویس)

### 🎫 سیستم تیکت و ارتباطات
- **دسته‌بندی دقیق**: `technical` (فنی)، `financial` (مالی)، `management` (مدیریتی)، `complaint` (انتقاد)، `suggestion` (پیشنهاد)
- **انتقاد ناشناس** از واحد دیگر (`is_anonymous` در جدول `tickets`)
- **کامنت** با امکان `internal` (داخلی برای مدیران) و `attachment_path`
- **وضعیت تیکت**: `open` → `in_progress` → `resolved` → `closed`
- **اولویت** (`priority`): `low` | `normal` | `high` | `urgent`

### 📢 اعلانات و دعوت
- **اعلانات** (`notifications`) با `notification_type` و `data` (JSON)
- **Queue با Redis** برای ارسال غیرهمزمان اعلانات
- **دعوت** (`invitations`) با توکن ۶۴ کاراکتری، انقضای ۷ روزه و نقش مشخص
- پذیرش دعوت (`/api/invitations/accept`) و ثبت خودکار در `building_members`

### 📦 ۱۰ ماژول اضافی حرفه‌ای (فاز ۶+)
1. **رزرو مشاعات** (`bookings` + `booking_slots`)
2. **اطلاعیه و تابلو اعلانات** (`announcements` با `is_pinned`)
3. **درخواست تعمیرات** (`maintenance_requests` + `assigned_technician_id`)
4. **نظرسنجی و رأی‌گیری** (`votes` + `vote_options` + `vote_results`)
5. **مدیریت مهمان و پارکینگ** (`visitors` با `visitor_car_plate`)
6. **قوانین و اسناد ساختمان** (`documents` با `document_type`)
7. **مصرف انرژی و آب** (`consumption_readings` با `consumption_type`)
8. **سیستم اضطراری** (`emergency_contacts` + `emergency_alerts`)
9. **جلسات ساختمان** (`meetings` + `meeting_minutes` با `minutes_content`)
10. **امتیازدهی و نظرات** (`reviews` + `review_categories` با `rating`)

---

## معماری فنی و استراکچر

### 📂 ساختار پوشه‌ها

```
Building_Management/
├── .htaccess                  # LiteSpeed Rewrite + Security Headers + PHP Settings
├── composer.json              # Dependencies (firebase/php-jwt, predis/predis, symfony/http-foundation)
├── public/
│   └── index.php              # Entry Point REST (PSR-7 Request/Response)
├── config/
│   ├── app.php                # Main Config (DB, Redis, JWT Secret, Storage, File Limits)
│   └── routes.php             # REST Route Definitions (PSR-4 Controller Classes)
├── src/
│   ├── Core/
│   │   ├── Kernel.php         # Bootstrap + Middleware Pipeline
│   │   ├── Router.php         # REST Router (Named Params {id})
│   │   ├── Request.php        # PSR-7 Inspired Request Handler
│   │   ├── Response.php       # JSON Response Handler
│   │   ├── Container.php      # Lightweight DI Container
│   │   ├── MiddlewarePipeline.php
│   │   └── Database.php       # PDO Singleton Connection
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── BuildingController.php
│   │   │   ├── CostController.php
│   │   │   ├── TicketController.php
│   │   │   ├── NotificationController.php
│   │   │   └── ExtraModulesController.php
│   │   └── Middleware/
│   │       ├── AuthMiddleware.php      # JWT Verification
│   │       ├── RateLimitMiddleware.php # Redis-Based Rate Limiting
│   │       ├── CorsMiddleware.php      # CORS Headers for Mobile/API
│   │       └── CacheMiddleware.php     # Query Caching for GET Requests
│   ├── Services/
│   │   ├── BuildingService.php
│   │   ├── UserService.php
│   │   ├── CostService.php
│   │   ├── TicketService.php
│   │   ├── NotificationService.php
│   │   ├── InvitationService.php
│   │   └── (All Phase 6+ Module Services)
│   ├── Repositories/
│   │   ├── BuildingRepository.php
│   │   ├── UserRepository.php
│   │   ├── CostRepository.php
│   │   ├── CostPaymentRepository.php
│   │   ├── BuildingHierarchyRepository.php
│   │   ├── InvitationRepository.php
│   │   ├── TicketRepository.php
│   │   ├── NotificationRepository.php
│   │   ├── PenaltySettingRepository.php
│   │   └── (10+ Additional Repositories for Phase 6+)
│   ├── Models/
│   │   ├── User.php
│   │   ├── Building.php
│   │   ├── BuildingHierarchy.php
│   │   ├── Block.php, Floor.php, Unit.php, CommonArea.php
│   │   ├── Cost.php, CostPayment.php, Receipt.php, Penalty.php, PenaltySetting.php
│   │   ├── Ticket.php, TicketComment.php, Notification.php, Invitation.php
│   │   └── (Booking, Announcement, Maintenance, Vote, Visitor, Document, Consumption, Emergency, Meeting, Review)
│   ├── Utilities/
│   │   ├── JwtHelper.php         # Firebase JWT Encode/Verify (HS256)
│   │   ├── Validator.php         # Input Validation Rules
│   │   ├── CacheHelper.php       # Redis Cache (Tag-Based Clear Support)
│   │   └── FileStorage.php       # Secure Local Storage (MIME + Size Check)
│   └── Exceptions/
│       ├── AppException.php
│       ├── AuthException.php
│       └── ValidationException.php
├── database/
│   ├── migrations/
│   │   ├── 001_create_users.php ... 020_create_reviews.php (20 Migration Files)
│   └── seeds/
│       └── DefaultData.php
├── storage/
│   ├── buildings/{id}/
│   │   ├── receipts/
│   │   ├── documents/
│   │   └── common_areas/
│   └── users/{id}/
│       └── avatar/
├── tests/
│   ├── Unit/
│   └── Integration/
├── docs/
│   ├── proposal.md              # Original 28-Question Proposal
│   ├── final_decisions.md       # Confirmed Architecture Decisions
│   ├── phase_1_2_complete.md    # Phase 1 & 2 Summary
│   └── project_complete_summary.md
├── scripts/
│   └── migrator.php             # Custom Migration Runner
└── all_migrations.sql           # Combined SQL for Direct MySQL Import
```

### 🔄 الگوی MVC واقعی
```
Request → Middleware Pipeline → Router → Controller → Service → Repository → Model (Entity) → PDO (Single DB)
```

---

## فازهای پیاده‌سازی شده

| فاز | عنوان | توضیحات | فایل‌های کلیدی |
|---|---|---|---|
| **فاز ۱-۲** | هسته و ساختمان | Kernel, Router, Middleware, JWT Auth, Dynamic Hierarchy, Storage System | `Kernel.php`, `Router.php`, `AuthMiddleware.php`, `CacheMiddleware.php`, `BuildingController.php`, `BuildingHierarchy.php` |
| **فاز ۳** | هزینه و پرداخت | Cost (Periodic/One-Time), Payment Flow, Receipt Upload, Dynamic Penalty, Discount | `CostService.php`, `CostController.php`, `PenaltySetting.php`, `Receipt.php`, `cost_payments` Table |
| **فاز ۴** | تیکت و اعلانات | Ticket Categories, Anonymous Complaints, Internal/External Comments, Notification Queue | `TicketService.php`, `TicketController.php`, `NotificationService.php`, `NotificationController.php` |
| **فاز ۵** | دعوت و عضویت | 7-Day Invitation Token, Role-Based Invitation, Member Join, Multi-Building Membership | `InvitationService.php`, `InvitationRepository.php`, `Invitation.php`, `building_members` Updates |
| **فاز ۶+** | ۱۰ ماژول اضافی | Bookings, Announcements, Maintenance, Voting, Visitors, Documents, Consumption, Emergency, Meetings, Reviews | `ExtraModulesController.php`, 3 Extra Migration Groups (`018-020`), All Extra Models |

---

## ساختار دیتابیس (۲۰ Migration)

### جداول اصلی (۱-۸)
- `users` — کاربران پایه
- `buildings` — ساختمان‌ها با `hierarchy_settings` (JSON)
- `building_members` — عضویت کاربران در ساختمان‌ها با نقش
- `building_hierarchy_settings` — تنظیمات سلسله مراتب داینامیک
- `blocks` — بلوک‌ها
- `floors` — طبقه‌ها با ارتباط به بلوک
- `units` — واحدها با `type` (residential/commercial)
- `common_areas` — مشاعات

### جداول هزینه و پرداخت (۹-۱۳)
- `costs` — هزینه‌ها با `cost_type`, `division_method`, `division_details` (JSON)
- `cost_payments` — پرداخت‌ها با وضعیت `pending`/`upload_receipt`/`confirmed`
- `receipts` — رسیدها با `is_public` و `file_path`
- `penalty_settings` — تنظیمات جریمه (`percentage`/`fixed_amount` + `delay_days`)
- `penalties` — جریمه‌های اعمال شده

### جداول ارتباطات و مدیریت (۱۴-۱۷)
- `invitations` — دعوت‌ها با `token`, `expires_at`, `role`
- `tickets` — تیکت‌ها با `category`, `is_anonymous`, `priority`
- `ticket_comments` — کامنت‌ها با `is_internal`
- `notifications` — اعلانات با `notification_type`, `data` (JSON), `is_read`

### جداول ماژول‌های اضافی (۱۸-۲۰)
- **۱۸**: `bookings`, `announcements`, `maintenance_requests`
- **۱۹**: `votes`, `vote_options`, `vote_results`, `visitors`, `documents`
- **۲۰**: `consumption_readings`, `emergency_contacts`, `emergency_alerts`, `meetings`, `meeting_minutes`, `reviews`, `review_categories`

**نکته مهم:** تمام جداول با `FOREIGN KEY` دقیق، `INDEX` بهینه و `ENGINE=InnoDB` با `CHARSET=utf8mb4` ساخته شده‌اند.

---

## نصب و راه‌اندازی

### پیش‌نیازها
- PHP >= 8.3 با `ext-pdo`, `ext-json`, `ext-openssl`, `ext-mbstring`
- MySQL 8 / MariaDB 11
- Redis (اختیاری — در صورت نبود، Cache به حالت پیش‌فرض کار می‌کند)
- LiteSpeed / Apache / Nginx (LiteSpeed با `.htaccess` توصیه می‌شود)
- Composer

### مراحل نصب سریع

```bash
# ۱. کلون یا دانلود پروژه
# مسیر فعلی: /home/user/Building_Management/

# ۲. نصب وابستگی‌ها
composer install

# ۳. ساخت دیتابیس
mysql -u root -p -e "CREATE DATABASE building_mgmt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ۴. اجرای Migration (دو روش)
# روش اول (با PHP):
php scripts/migrator.php

# روش دوم (بدون PHP — فقط SQL):
mysql -u root -p building_mgmt < all_migrations.sql

# ۵. تنظیم JWT Secret
# در config/app.php خط ۱۲، مقدار JWT_SECRET را با یک رشته تصادفی طولانی جایگزین کنید
# مثال:
# 'JWT_SECRET' => 'MyVeryLongAndRandomSecretKeyForBuildingManagement2026!@#'

# ۶. تنظیم Redis (در صورت وجود)
# در config/app.php خطوط ۱۶-۲۰ اطلاعات Redis را تنظیم کنید

# ۷. تنظیم دسترسی پوشه ذخیره‌سازی
chmod -R 755 storage/
mkdir -p storage/buildings storage/users

# ۸. راه‌اندازی با PHP Built-in Server (تست سریع)
php -S 0.0.0.0:8000 -t public/

# ۹. راه‌اندازی با LiteSpeed (تولید)
# DocumentRoot را به مسیر public/ پروژه تنظیم کنید
# مثال: /home/user/Building_Management/public
# فایل .htaccess از قبل تنظیم شده است
```

---

## پیکربندی پروژه

### فایل `config/app.php`

```php
// نمونه تنظیمات اصلی
const JWT_SECRET = '...';          // حتماً تغییر دهید!
const JWT_EXPIRY = 3600;           // ۱ ساعت
const REDIS_HOST = '127.0.0.1';    // در صورت وجود Redis
const REDIS_PORT = 6379;
const STORAGE_PATH = __DIR__ . '/../../storage';
const MAX_FILE_SIZE = 5 * 1024 * 1024;  // ۵ مگابایت
```

**نکات امنیتی مهم:**
- `JWT_SECRET` حتماً باید یک رشته طولانی و تصادفی باشد (حداقل ۳۲ کاراکتر)
- در محیط تولید (`APP_ENV = 'production'`)، نمایش خطاها غیرفعال می‌شود
- `.htaccess` از دسترسی مستقیم به `storage/`, `tests/`, `docs/` و فایل‌های حساس (`.env`, `.ini`) جلوگیری می‌کند

---

## نحوه کار با REST API

تمام Endpointها با پیشوند `api/` از `public/index.php` در دسترس هستند.

### ۱. احراز هویت (Authentication)

```bash
# ثبت‌نام
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"مدیر ساختمان","email":"admin@test.com","password":"123456"}'

# ورود
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"123456"}'

# پاسخ:
# {"success":true,"message":"Login successful","data":{"user":{"id":1,...},"token":"eyJ0eXAi..."}}
```

### ۲. ساختمان (Building)

```bash
TOKEN="eyJ0eXAi..."

# ایجاد ساختمان
curl -X POST http://localhost:8000/api/buildings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"مجتمع مسکونی آفتاب","address":"تهران، ولیعصر"}'

# دریافت ساختمان‌ها
curl -X GET http://localhost:8000/api/buildings \
  -H "Authorization: Bearer $TOKEN"

# به‌روزرسانی سلسله مراتب داینامیک
curl -X PUT http://localhost:8000/api/buildings/1/hierarchy/settings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"has_blocks":true,"has_floors":true,"has_units":true,"has_common_areas":true}'
```

### ۳. هزینه و پرداخت (Cost & Payment)

```bash
# ایجاد هزینه (دوره‌ای با تقسیم بر اساس سهم ثابت)
curl -X POST http://localhost:8000/api/costs \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"title":"شارژ ماهانه","amount":500000,"cost_type":"periodic","target_audience":"all","division_method":"fixed_share","division_details":{"shares":{"unit_101":1,"unit_102":2}}}'

# ارسال پرداخت
curl -X POST http://localhost:8000/api/payments/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"cost_id":1,"amount_paid":500000,"receipt_is_public":false}'

# آپلود رسید (با فایل)
curl -X POST http://localhost:8000/api/payments/1/upload-receipt \
  -H "Authorization: Bearer $TOKEN" \
  -F "receipt=@/path/to/receipt.jpg" \
  -F "is_public=0"

# تأیید پرداخت توسط مدیر (اعمال خودکار جریمه در صورت تأخیر)
curl -X POST http://localhost:8000/api/payments/1/confirm \
  -H "Authorization: Bearer $TOKEN"

# تنظیم جریمه داینامیک (درصد ۵٪ برای تأخیر ۳ روزه)
curl -X POST http://localhost:8000/api/penalty-settings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"penalty_type":"percentage","penalty_value":5,"delay_days":3,"is_active":true}'
```

### ۴. تیکت و اعلانات (Ticket & Notification)

```bash
# ایجاد تیکت (انتقاد ناشناس از واحد دیگر)
curl -X POST http://localhost:8000/api/tickets \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"unit_id":101,"category":"complaint","is_anonymous":true,"title":"مشکل سر و صدا","description":"واحد ۱۰۲ در ساعات شب سر و صدا ایجاد می‌کند","priority":"high"}'

# افزودن کامنت داخلی (فقط برای مدیران قابل مشاهده)
curl -X POST http://localhost:8000/api/tickets/1/comments \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"comment":"بررسی شد و به تکنسین ارجاع داده شد","is_internal":true}'

# دریافت اعلانات
curl -X GET http://localhost:8000/api/notifications \
  -H "Authorization: Bearer $TOKEN"

# علامت‌گذاری اعلانات به عنوان خوانده شده
curl -X POST http://localhost:8000/api/notifications/1/read \
  -H "Authorization: Bearer $TOKEN"
```

### ۵. دعوت و عضویت (Invitation & Member)

```bash
# ارسال دعوت به عنوان حسابدار
curl -X POST http://localhost:8000/api/buildings/1/invitations \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"invited_email":"accountant@test.com","role":"accountant"}'

# دریافت اعضای ساختمان
curl -X GET http://localhost:8000/api/buildings/1/members \
  -H "Authorization: Bearer $TOKEN"

# پذیرش دعوت
curl -X POST http://localhost:8000/api/invitations/accept \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"token":"TOKEN_FROM_INVITATION"}'
```

### ۶. ماژول‌های اضافی (Phase 6+)

```bash
# رزرو سالن
curl -X POST http://localhost:8000/api/bookings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"common_area_id":1,"booking_date":"2026-09-01","start_time":"18:00","end_time":"22:00"}'

# درخواست تعمیرات
curl -X POST http://localhost:8000/api/maintenance \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"title":"تعمیر لوله‌کشی طبقه ۳","description":"نشت آب در راهرو"}'

# رأی‌گیری
curl -X POST http://localhost:8000/api/votes \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"building_id":1,"title":"تصمیم در مورد تغییر نمای ساختمان","description":"رأی‌گیری برای رنگ جدید"}'
```

---

## سیستم کش و عملکرد

### Redis Cache Layer
- `CacheMiddleware.php`: کش خودکار برای تمام درخواست‌های `GET` با TTL قابل تنظیم (۵ دقیقه پیش‌فرض)
- `CacheHelper.php`: دسترسی به Redis با `predis/predis`
- `RateLimitMiddleware.php`: محدودیت نرخ بر اساس IP و مسیر (`ratelimit:{ip}:{path}`) با TTL ۶۰ ثانیه
- `NotificationService.php`: Queue با Redis برای پردازش غیرهمزمان (`notification:queue:{id}`)

### OPcache و LiteSpeed
- `.htaccess` شامل `php_value memory_limit 256M` و تنظیمات `upload_max_filesize`
- `php_value max_execution_time 30`
- LiteSpeed از `.htaccess` به طور کامل پشتیبانی می‌کند

---

## امنیت حرفه‌ای

| لایه امنیتی | پیاده‌سازی |
|---|---|
| **JWT Auth** | `AuthMiddleware.php` با `Firebase\JWT\JWT` (HS256) |
| **Rate Limiting** | `RateLimitMiddleware.php` با Redis (`ratelimit:` key) |
| **SQL Injection** | فقط `Prepared Statements` (PDO) در تمام Repositoryها |
| **XSS Protection** | `Content-Type: application/json` در تمام Responseها |
| **CSRF Protection** | `CorsMiddleware.php` با محدودیت `Authorization` Header |
| **File Upload Security** | `FileStorage.php` با بررسی `MIME Type` (`finfo_file`)، `Extension` و محدودیت حجم (`AppConfig::MAX_FILE_SIZE`) |
| **Path Traversal** | `storage/` با `.htaccess` `Deny from all` و `open_basedir` محدود |
| **Multi-Tenancy Isolation** | هر کوئری با `WHERE building_id = ?` فیلتر می‌شود |
| **Audit Trail** | `audit_logs` قابل افزودن (ساختار آماده در Repositoryها) |

---

## ذخیره‌سازی فایل‌ها

### ساختار تفکیک‌شده
```
storage/
├── buildings/
│   └── {building_id}/
│       ├── receipts/
│       │   └── {cost_payment_id}/
│       │       └── receipt.jpg
│       ├── documents/
│       └── common_areas/
└── users/
    └── {user_id}/
        └── avatar/
```

### مدیریت دسترسی
- `receipt_is_public` (`TINYINT`) در جدول `cost_payments`: اگر `0` باشد فقط مدیر (`confirmed_by`) و پرداخت‌کننده (`user_id`) می‌توانند فایل را ببینند
- اگر `1` باشد، تمام اعضای ساختمان (`building_members`) می‌توانند رسید را مشاهده کنند
- `FileStorage::getPublicUrl()` مسیر نسبی را تولید می‌کند اما `.htaccess` از دسترسی مستقیم به `storage/` جلوگیری می‌کند (فقط از طریق Controller قابل دسترسی است)

---

## مستندات فنی پروژه

| فایل مستند | توضیحات |
|---|---|
| `docs/proposal.md` | پروپوزال اولیه با ۲۸ سوال کلیدی و معماری پیشنهادی |
| `docs/final_decisions.md` | تصمیمات نهایی تأیید شده توسط کاربر (۹ مورد کلیدی + ۱۰ ماژول اضافی) |
| `docs/phase_1_2_complete.md` | خلاصه فاز ۱ و ۲ (هسته و ساختمان داینامیک) |
| `docs/project_complete_summary.md` | خلاصه کامل تمام فازها با آمار دقیق (۸۷ فایل، ۲۰ Migration) |

---

## راهنمای توسعه‌دهنده

### افزودن ماژول جدید
1. جدول را در `database/migrations/` بسازید (`021_...`)
2. مدل را در `src/Models/` بسازید
3. Repository را در `src/Repositories/` بسازید
4. Service را در `src/Services/` بسازید (منطق کسب‌وکار)
5. Controller را در `src/Http/Controllers/` بسازید
6. مسیر را در `config/routes.php` اضافه کنید
7. Migration را با `php scripts/migrator.php` اجرا کنید

### تست و کیفیت کد
- `tests/Unit/` و `tests/Integration/` برای PHPUnit آماده هستند
- `composer.json` شامل `phpunit/phpunit` در `require-dev`
- `phpstan` و `psalm` با افزودن به `composer.json` قابل استفاده هستند

### اسکریپت‌های کمکی
- `scripts/migrator.php`: اجرای خودکار Migration
- `all_migrations.sql`: فایل SQL ترکیبی برای اجرا سریع در MySQL

---

## مجوز و کپی‌رایت

این پروژه تحت مجوز **MIT** منتشر شده و قابل فروش، شخصی‌سازی و استفاده تجاری است. تمام کدها از صفر نوشته شده و هیچ وابستگی سنگین به فریم‌ورک خاصی ندارد.

**نکته مهم برای فروش:** با توجه به ساختار `White Label` (`custom_name`, `custom_logo_path`, `theme_color` در `buildings`)، هر ساختمان می‌تواند برند، نام و رنگ خود را داشته باشد و سیستم به راحتی به عنوان SaaS چند مستاجره (`Multi-Tenant`) قابل فروش است.

---

## تماس و پشتیبانی

این پروژه به طور کامل در شاخه `arena/019ffbd3-building-management` ذخیره شده و Pull Request آن در GitHub ثبت شده است (`https://github.com/ahmad75naraghi/Building_Management/pull/1`).

در صورت نیاز به راه‌اندازی در سرور تولیدی، توسعه ماژول جدید، یا شخصی‌سازی برای مشتری خاص، آماده راهنمایی هستم.
