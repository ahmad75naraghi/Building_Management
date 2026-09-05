# ✅ فاز ۱ و ۲ — پیاده‌سازی شده (تکمیل شده)

## 📦 ساختار پروژه (۴۵+ فایل حرفه‌ای)

### هسته سیستم (Core)
- `Kernel.php` — بوت‌استرپ + Pipeline Middleware
- `Router.php` — روتر REST با پارامترهای نام‌دار (`{id}`)
- `Request.php` / `Response.php` — PSR-7 سبک
- `Container.php` — Dependency Injection ساده
- `MiddlewarePipeline.php` — اجرای زنجیره‌ای Middleware
- `Database.php` — اتصال PDO با Singleton

### Middleware (امنیت و عملکرد)
- `AuthMiddleware.php` — تایید JWT با بررسی مسیرهای عمومی
- `RateLimitMiddleware.php` — محدودیت با Redis (`ratelimit:{ip}:{path}`)
- `CorsMiddleware.php` — سرتیفیکیت CORS کامل
- `CacheMiddleware.php` — کش HTTP با Redis برای GET

### کنترلرها (REST Controllers)
- `AuthController.php` — ثبت‌نام، ورود، رفرش، خروج
- `BuildingController.php` — ساختمان + سلسله مراتب داینامیک + بلاک + طبقه + واحد + مشاعات + دعوت + اعضا

### سرویس‌ها (Business Logic)
- `BuildingService.php` — ایجاد، دریافت، حذف، به‌روزرسانی سلسله مراتب
- `UserService.php` — ثبت‌نام و احراز هویت

### مخازن داده (Repositories — PSR Style)
- `BuildingRepository.php`
- `BuildingHierarchyRepository.php`
- `UserRepository.php`

### مدل‌ها (Entities)
- `User.php`, `Building.php`, `BuildingHierarchy.php`
- `Block.php`, `Floor.php`, `Unit.php`, `CommonArea.php`

### ابزارهای کمکی (Utilities)
- `JwtHelper.php` — تولید و تایید توکن با `firebase/php-jwt`
- `Validator.php` — اعتبارسنجی داده‌ها
- `CacheHelper.php` — کش Redis با `predis/predis`
- `FileStorage.php` — ذخیره‌سازی محلی با بررسی MIME و محدودیت حجم

### امنیت و خطاها
- `AppException.php`, `AuthException.php`, `ValidationException.php`

### دیتابیس
- ۸ Migration حرفه‌ای با Foreign Key و Index دقیق
- `database/migrations/` از ۰۱ تا ۰۸
- `scripts/migrator.php` — اجرای خودکار Migration

### تنظیمات و مسیرها
- `config/app.php` — تنظیمات JWT، Redis، محدودیت فایل، مسیر ذخیره‌سازی
- `config/routes.php` — تعریف کامل مسیرهای REST
- `.htaccess` — Rewrite LiteSpeed + هدرهای امنیتی + محدودیت فایل

---

## 🚀 ویژگی‌های پیاده‌سازی شده

| ویژگی | وضعیت |
|---|---|
| PHP 8.3 + Composer + PSR-4 | ✅ |
| MVC سفارشی (بدون Laravel) | ✅ |
| REST API از روز اول | ✅ |
| JWT Authentication | ✅ |
| Single DB / Single Schema | ✅ |
| سلسله مراتب داینامیک (JSON) | ✅ |
| ساختمان با نقش‌های مختلف در چند ساختمان | ✅ |
| دعوت با انقضا و نقش مشخص | ✅ (در جدول) |
| هزینه‌ها و پرداخت‌ها | ⏳ (فاز ۳) |
| تیکت با ناشناس و دسته‌بندی | ⏳ (فاز ۴) |
| اعلانات با Redis Queue | ⏳ (فاز ۵) |
| رزرو مشاعات + ۱۰ ماژول اضافی | ⏳ (فازهای بعد) |
| Audit Log + Rate Limit دقیق | ✅ در Middleware |
| Migration سفارشی | ✅ |
| Cache با Redis | ✅ |
| File Storage تفکیک‌شده | ✅ |

---

## 📋 برای ادامه کار (فاز ۳ به بعد)

۱. **فاز ۳**: ماژول هزینه (`costs`, `cost_payments`, `receipts`, `penalty_settings`)
۲. **فاز ۴**: ماژول تیکت (`tickets`, `ticket_comments`, `notifications`)
۳. **فاز ۵**: ماژول دعوت (`invitations`) + اعلانات (`notifications` با Queue)
۴. **فاز ۶**: ماژول‌های اضافی (رزرو، تعمیرات، رأی‌گیری و ...)

> لطفاً بگویید کدام فاز را ادامه دهم یا آیا نیاز به تغییر در ساختار فعلی دارید.
