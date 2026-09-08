# 🗺️ نقشهٔ سایت — سیستم مدیریت ساختمان

> نسخهٔ سند: سپتامبر ۲۰۲۶ — موازی با شاخهٔ توسعهٔ فعلی
> مشخصات کامل هر صفحه (دکمه‌ها، مودال‌ها، دسترسی‌ها، اندپوینت‌ها) در [`pages.md`](pages.md) آمده است.

---

## ۱. ناوبری سراسری

### منوی پایین (در همهٔ صفحات اصلی — ۵ آیتم)

| آیتم | مقصد |
|---|---|
| داشبورد | `dashboard.php` |
| پیام‌های من | `notifications.php` |
| ساختمان‌های من | `index.php` |
| تقویم | `calendar.php` |
| حساب کاربری | `profile.php` |

### هدر صفحات

- 🔔 زنگولهٔ اعلانات (با شمارندهٔ خوانده‌نشده) → `notifications.php`
- 👤 آواتار → `profile.php`
- دکمهٔ بازگشت (در زیرصفحه‌ها)

### نقاط ورود

- کاربر لاگین‌نکرده از هر صفحه‌ای به `auth.php` هدایت می‌شود.
- کاربر ثبت‌نام نیمه‌کاره (بدون نام/رمز) به گام تکمیل در `auth.php` هدایت می‌شود.
- مقصد دعوتنامه: `invite.php` ← پس از پذیرش، ورود/ثبت‌نام در `auth.php`.

---

## ۲. نمودار جریان اصلی

```
auth.php (ورود / ثبت‌نام یکپارچه)
   │
   ▼
dashboard.php ◄══════════════ منوی پایین: «داشبورد»
   │  (هاب اصلی: ساختمان فعال + ویجت‌های خلاصه)
   │
   ├─► index.php «ساختمان‌های من» ──► building_add.php
   │        │
   │        ▼
   │   building_view.php ◄══════════ هاب دوم (پروفایل ساختمان)
   │        │  دسترسی سریع: مالی، حسابداری، تیکت، اطلاعیه، تعمیرات، اعضا
   │        │
   │        ├── ساختار فیزیکی ─► floors / blocks / units / common_areas
   │        │
   │        ├── مالی ─► costs.php ⇄ accounting.php
   │        │           consumption.php
   │        │
   │        ├── اعضا ─► members.php ──► bulk_users.php
   │        │
   │        └── ماژول‌ها ─► tickets / announcements / meetings / bookings /
   │                        visitors / maintenance / documents / votes /
   │                        reviews / emergency_contacts
   │
   ├─► reports.php (گزارش‌ها + خروجی CSV — با لینک به ریز هر بخش)
   ├─► audit_logs.php (لاگ اقدامات — مدیر)
   └─► calendar.php (تقویم شمسی جلسات/رزروها)

حساب کاربری:  profile.php ─► profile_edit.php / change_password.php
                             / notifications.php / logout.php
```

---

## ۳. گروه‌های صفحه

### 🔐 ورود و حساب کاربری (۷)
`auth` · `profile` · `profile_edit` · `change_password` · `notifications` · `invite` · `logout`

### 🏢 ساختمان‌ها — قطب‌ها (۶)
`dashboard` (هاب اصلی) · `index` (لیست ساختمان‌ها) · `building_view` (هاب ساختمان) ·
`building_add` · `building_edit` · `building_delete`

### 🏗️ ساختار فیزیکی (۴)
`floors` · `blocks` · `units` · `common_areas`

### 💰 مالی (۴)
`costs` (هزینه‌ها و شارژ) · `accounting` (حسابداری مدیر) · `consumption` (مصرف انرژی) · `reports` (گزارش‌ها)

### 📋 ماژول‌های عملیاتی (۱۲)
`tickets` + `ticket_view` · `announcements` · `meetings` · `bookings` · `visitors` ·
`maintenance` · `documents` (+`document_download`) · `votes` · `reviews` · `emergency_contacts`

### 👥 اعضا و نمای کلی (۴)
`members` · `bulk_users` · `calendar` · `audit_logs`

**مجموع: ۳۷ صفحه**

---

## ۴. الگوی مشترک صفحه‌ها

همهٔ صفحه‌های ماژول از یک الگو پیروی می‌کنند:

1. **لیست** کارت/جدول‌محور با فیلتر
2. **مودال افزودن** (دکمهٔ اصلی)
3. **مودال ویرایش** (با پیش‌پر شدن از `data-set-*`)
4. **حذف با تأیید** (`data-confirm`)
5. اتصال به ساختمان فعال با `?building_id=`
6. فرم‌ها با `form_action` + فیلد CSRF
7. هدر/فوتر و منوی پایین مشترک (`includes/header.php` / `includes/footer.php`)

## ۵. قاعدهٔ دسترسی در یک نگاه

| سطح | صفحه‌ها/کارها |
|---|---|
| فقط ورود | همهٔ صفحه‌ها |
| عضو ساختمان | مشاهدهٔ ماژول‌ها، پرداخت هزینه‌ها، ثبت تیکت/رزرو/نظر، اعلانات |
| مدیر ساختمان | صدور/تأیید/رد پرداخت، حسابداری و پرداخت مستقیم، دعوت و افزودن گروهی اعضا، لاگ اقدامات، ویرایش/حذف ساختمان، تنظیمات شارژ و جریمه |

---

*این سند با تغییر صفحه‌ها باید به‌روز شود؛ مشخصات ریز هر صفحه در [`pages.md`](pages.md).*
