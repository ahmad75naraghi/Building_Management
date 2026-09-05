# 🚨 راهنمای رساندن کارها به `main` — فایل بازیابی

## وضعیت واقعی (با شواهد تأییدشده)

**کار کامل فقط در همین محیط محلی وجود دارد و هرگز به GitHub پوش نشده است.**
ادعای چت قبلی («کاری برای push باقی نمانده») **اشتباه** بود؛ آن چت در یک محیط تازه (کلون تازه از GitHub) کار را ندیده بود و نتیجه گرفته بود چیزی نیست.

شواهد مقایسه `origin/main` (کامیت `aeeeccf`) با کار کامل (کامیت `7a3d0d9`):

| مورد | `main` (aeeeccf) | کار کامل (7a3d0d9) |
|---|---|---|
| تعداد روت‌های API | **۴۵** | **۹۰** |
| `src/Services/UnitService.php` | ❌ ندارد | ✅ دارد |
| روت `PUT /api/units/{id}` و `DELETE /api/units/{id}` | ❌ ندارد | ✅ دارد |
| روت `PUT /api/auth/me` (ویرایش پروفایل) | ❌ ندارد | ✅ دارد |
| `profile_edit.php` | ❌ ندارد | ✅ دارد |
| migration های `021` و `022` | ❌ ندارد | ✅ دارد |
| `units.php` با وضعیت سکونت (`occupancy_status`) | ❌ ندارد | ✅ دارد |
| لینک ثبت‌نام در `login.php` | ❌ ندارد | ✅ دارد |
| Postman (ریکوئست) | — | ۹۱ ریکوئست / پوشش کامل ۹۰ روت |

> نتیجه: **گیت واقعاً آپدیت نیست.** کاربر درست می‌گوید.

## چرا نرسید؟
- جلسه‌ای که این کارها را انجام داد **بسته** بود (PR آن merge شده) و push روی GitHub غیرفعال بود.
- علاوه بر آن، این سندباکس **به اینترنت وصل نیست** (تست‌ها: github.com و همه هاست‌ها → بدون اتصال).
- پس کار فقط به‌صورت محلی (commit `7a3d0d9`) ثبت شد.

---

## فایل‌های بازیابی (در ریشه همین پوشه)

| فایل | کاربرد |
|---|---|
| **`arena_work_snapshot.bundle`** (۱.۸ مگابایت) | پشتیبان کامل و خودکفا از کل کار — در هر ماشینی قابل `clone` |
| **`arena_7a3d0d9.patch`** (۹.۴ مگابایت) | patch قابل اعمال روی نسخه قدیمی |

---

## روش ۱) سریع‌ترین: جلسه جدید Arena از همین محیط

یک **جلسه جدید** در همین ریپو باز کنید (روی همین محیط/ورک‌اسپیس) و بگویید:

```
همه فایل‌های این ریپو را commit و به شاخه جلسه push کن،
سپس PR به main باز کن. اگر اجازه push مستقیم به main داری، همان را انجام بده.
```

در جلسه جدید (که بسته نیست) این دستورها اجرا می‌شود:
```bash
git add -A
git commit -m "feat: تکمیل پروژه — واحدها/مالک/مستاجر/ساکن، ویرایش پروفایل، اصلاح لینک‌ها"
git push origin <branch-session>
# سپس PR به main
```

## روش ۲) روی ماشین خودت (مطمئن‌ترین)

```bash
# ۱) دانلود فایل arena_work_snapshot.bundle از همین محیط
# ۲) بازیابی:
git clone arena_work_snapshot.bundle building_work
cd building_work
git checkout -b restore-main origin/arena-snapshot-branch

# ۳) اتصال به GitHub و push به main:
git remote add origin https://github.com/ahmad75naraghi/Building_Management.git
git push origin restore-main:main
```

یا با patch (اگر قبلاً کلون `main` داری):
```bash
git clone https://github.com/ahmad75naraghi/Building_Management.git bm
cd bm
git checkout main
git apply --3way /path/to/arena_7a3d0d9.patch   # یا: git am arena_7a3d0d9.patch
git add -A && git commit -m "feat: تکمیل پروژه"
git push origin main
```

## روش ۳) بازگردانی bundle به‌صورت شاخه واقعی (برای جلسه جدید Arena)

```bash
git fetch arena_work_snapshot.bundle arena-snapshot-branch:arena/01a052fa-building-management
git checkout arena/01a052fa-building-management   # کل کار با یک commit
# سپس push و PR به main
```

---

## بعد از رسیدن به main — فراموش نکن
در سرور (file.falnic.com) اجرا شود تا دیتابیس به‌روز شود:
```bash
cd /b && php scripts/migrator.php && composer dump-autoload
```
(ستون‌های جدید `units.owner_resident` و `invitations.unit_id` + کلاس `UnitService`)

*تاریخ بررسی: ۲۰۲۶-۰۹-۰۱ — شواهد از `git show aeeeccf:...` در مقابل `git show 7a3d0d9:...`*
