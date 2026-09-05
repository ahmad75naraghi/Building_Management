# ✅ تصمیمات نهایی استراکچر (تایید شده توسط کاربر)

## ۱. سلسله مراتب ساختمان
- **کاملاً داینامیک** (`building_hierarchy_settings` JSON)
- هر ساختمان می‌تواند بلوک، طبقه، واحد را فعال/غیرفعال کند.
- یک ساختمان می‌تواند بدون بلوک و فقط با واحد باشد.

## ۲. نقش‌ها و عضویت
- کاربر می‌تواند در **چند ساختمان همزمان** با **نقش‌های متفاوت** عضو باشد (`building_members`).
- نقش‌های پایه: `manager`, `resident`, `tenant`, `accountant` + امکان سفارشی‌سازی.

## ۳. سیستم دعوت
- فقط **مدیر ساختمان** می‌تواند دعوت کند.
- لینک دعوت با **نقش مشخص** و **انقضا ۷ روزه**.
- اگر کاربر قبلاً ثبت‌نام کرده، مستقیماً اضافه می‌شود؛ در غیر این صورت ثبت‌نام می‌کند.

## ۴. هزینه‌ها و پرداخت
- **دوره‌ای** (ماهانه/فصلی) و **یک‌باره** (مثلاً تعمیرات).
- **تقسیم هزینه** قابل انتخاب: بر اساس متراژ، تعداد ساکنان، یا سهم ثابت.
- **جریمه داینامیک**: درصد یا ثابت + روز تاخیر (قابل تنظیم توسط مدیر ساختمان).
- **تخفیف پرداخت زودهنگام**: قابل تنظیم (مثلاً ۵٪ برای پرداخت قبل از موعد).
- وضعیت پرداخت: `pending` → `upload_receipt` → `confirmed` (توسط مدیر).

## ۵. تیکت و ارتباطات
- **دسته‌بندی تیکت**: فنی، مالی، مدیریتی.
- **انتقاد از واحد دیگر**: قابل ارسال **ناشناس**.
- هر تیکت می‌تواند **چند کاربر درگیر** داشته باشد.

## ۶. ذخیره‌سازی و امنیت
- **Storage محلی** تفکیک‌شده: `/storage/buildings/{id}/receipts/` و `/storage/users/{id}/`.
- دسترسی به فایل رسید: **قابل تنظیم** (عمومی یا خصوصی) توسط مدیر ساختمان.
- محدودیت حجم فایل: **۵ مگابایت** (قابل تنظیم).
- بدون رمزنگاری اضافی (اعتماد به LiteSpeed + `.htaccess` deny).

## ۷. گزارش‌گیری
- خروجی: **JSON** (REST) + **CSV**.
- PDF و Excel در نسخه‌های بعدی.

## ۸. زبان
- **فارسی** (پیش‌فرض) — بدون نیاز به انگلیسی در فاز اول.

## ۹. کش و عملکرد
- **Redis**: Cache Query + Rate Limit + Queue اعلانات + Session JWT.
- **OPcache Preload**: بارگذاری کلاس‌های اصلی.
- **LiteSpeed**: `.htaccess` + Rewrite برای REST.

## ۱۰. امنیت حرفه‌ای
- JWT HS256 با Refresh Token در Redis.
- Rate Limiting بر اساس IP + User ID.
- SQL Injection فقط با Prepared Statements.
- File Upload Validation: MIME + Extension + Magic Number.

---

## 📁 ساختار نهایی پروژه (تایید شده)

```
Building_Management/
├── public/
│   └── index.php              # Entry REST (PSR-7)
├── config/
│   ├── app.php                # Config اصلی (DB, Redis, JWT, Storage)
│   ├── routes.php             # تعریف مسیرها
│   └── database.php           # اتصال PDO
├── src/
│   ├── Core/
│   │   ├── Kernel.php         # Bootstrap + Middleware Pipeline
│   │   ├── Router.php         # Router سفارشی (REST)
│   │   ├── Request.php        # PSR-7 Request
│   │   ├── Response.php       # PSR-7 Response (JSON)
│   │   └── Container.php      # DI ساده
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── BuildingController.php
│   │   │   ├── UserController.php
│   │   │   ├── CostController.php
│   │   │   ├── TicketController.php
│   │   │   └── NotificationController.php
│   │   └── Middleware/
│   │       ├── AuthMiddleware.php     # JWT Verify
│   │       ├── RateLimitMiddleware.php
│   │       ├── CorsMiddleware.php
│   │       └── CacheMiddleware.php
│   ├── Services/               # Business Logic
│   │   ├── BuildingService.php
│   │   ├── UserService.php
│   │   ├── CostService.php
│   │   ├── InvitationService.php
│   │   ├── TicketService.php
│   │   ├── NotificationService.php
│   │   └── PenaltyService.php
│   ├── Repositories/           # Data Access (PSR style)
│   │   ├── UserRepository.php
│   │   ├── BuildingRepository.php
│   │   ├── BuildingHierarchyRepository.php
│   │   ├── CostRepository.php
│   │   ├── CostPaymentRepository.php
│   │   ├── InvitationRepository.php
│   │   ├── TicketRepository.php
│   │   └── NotificationRepository.php
│   ├── Models/                 # Entities
│   │   ├── User.php
│   │   ├── Building.php
│   │   ├── BuildingHierarchy.php
│   │   ├── Unit.php
│   │   ├── Cost.php
│   │   ├── CostPayment.php
│   │   ├── Invitation.php
│   │   ├── Ticket.php
│   │   └── Notification.php
│   ├── Utilities/
│   │   ├── Validator.php
│   │   ├── JwtHelper.php
│   │   ├── ImageProcessor.php
│   │   ├── CacheHelper.php
│   │   └── FileStorage.php
│   └── Exceptions/
│       ├── AppException.php
│       ├── AuthException.php
│       └── ValidationException.php
├── database/
│   ├── migrations/
│   │   ├── 001_create_users.php
│   │   ├── 002_create_buildings.php
│   │   ├── 003_create_building_members.php
│   │   ├── 004_create_hierarchy_settings.php
│   │   ├── 005_create_blocks_floors_units.php
│   │   ├── 006_create_common_areas.php
│   │   ├── 007_create_costs.php
│   │   ├── 008_create_cost_payments.php
│   │   ├── 009_create_receipts.php
│   │   ├── 010_create_invitations.php
│   │   ├── 011_create_tickets.php
│   │   ├── 012_create_notifications.php
│   │   ├── 013_create_penalty_settings.php
│   │   └── 014_create_penalties.php
│   └── seeds/
│       └── DefaultData.php
├── storage/
│   ├── buildings/
│   │   └── {building_id}/
│   │       ├── receipts/
│   │       ├── documents/
│   │       └── common_areas/
│   └── users/
│       └── {user_id}/
│           └── avatar/
├── tests/
│   ├── Unit/
│   └── Integration/
├── docs/
│   └── proposal.md
├── .htaccess                  # LiteSpeed Rewrite + Security
├── composer.json
└── index.php                  # Public entry
```

---

## ۱۱. ماژول‌های اضافی حرفه‌ای (تایید شده — همه ۱۰ مورد)

| شماره | ماژول | توضیح کوتاه | جدول پیشنهادی |
|---|---|---|---|
| ۱ | **رزرو مشاعات** | رزرو سالن، پارکینگ مهمان، استخر با محدودیت زمانی | `bookings`, `booking_slots` |
| ۲ | **اطلاعیه و تابلو اعلانات** | ارسال و پین اطلاعیه توسط مدیر | `announcements` |
| ۳ | **درخواست تعمیرات** | ثبت، ارجاع به تکنسین، پیگیری وضعیت | `maintenance_requests`, `maintenance_comments` |
| ۴ | **نظرسنجی و رأی‌گیری** | رأی‌گیری برای تصمیمات ساختمان | `votes`, `vote_options`, `vote_results` |
| ۵ | **مدیریت مهمان و پارکینگ** | ثبت مهمان، کارت موقت پارکینگ | `visitors`, `visitor_passes` |
| ۶ | **قوانین و اسناد** | آپلود اساسنامه، قوانین، قراردادها | `documents`, `document_access` |
| ۷ | **مصرف انرژی و آب** | ثبت دستی مصرف هر واحد (آماده برای API آینده) | `consumption_readings` |
| ۸ | **سیستم اضطراری** | شماره‌های اضطراری و پیام سریع | `emergency_contacts`, `emergency_alerts` |
| ۹ | **جلسات ساختمان** | برنامه‌ریزی، دعوت، ثبت صورت‌جلسه | `meetings`, `meeting_minutes` |
| ۱۰ | **امتیازدهی و نظرات** | امتیاز به خدمات و گزارش کیفیت | `reviews`, `review_categories` |

> تمام این ماژول‌ها در ساختار پروژه (Controllers, Services, Repositories, Models, Migrations) لحاظ می‌شوند اما در فازهای جداگانه پیاده‌سازی می‌شوند.

---

## 🚀 آماده شروع فاز ۱

> **فاز ۱**: هسته (Kernel, Router, Middleware, JWT Auth, Container) + اتصال دیتابیس (PDO) + `composer.json` با PSR-4.

لطفاً تایید نهایی بدهید تا کدنویسی دقیق با تمام جزئیات بالا شروع شود.
