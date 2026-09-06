# راه‌اندازی یکپارچگی پیوسته (CI)

فایل `github-actions.yml.example` یک workflow آمادهٔ گیت‌هاب اکشنز است.

به دلیل محدودیت دسترسی، این فایل نمی‌تواند به‌صورت خودکار در مسیر
`.github/workflows/` ثبت شود. برای فعال‌کردنش، خودتان یک بار این را اجرا کنید:

```bash
mkdir -p .github/workflows
cp ci/github-actions.yml.example .github/workflows/ci.yml
git add .github/workflows/ci.yml
git commit -m "ci: فعال‌سازی گیت‌هاب اکشنز"
git push
```

## این workflow چه کاری می‌کند؟

روی هر push و هر Pull Request:

1. **بررسی نحوی** همه فایل‌های PHP با `php -l`
2. **اجرای تست‌ها** با `php tests/Integration/run.php`
3. **بررسی آمادگی تولید** با `php scripts/healthcheck.php --ci`

اگر هر کدام شکست بخورد، بیلد قرمز می‌شود.

## اجرای همین بررسی‌ها به‌صورت محلی

```bash
composer test      # تست‌ها
composer health    # بررسی سلامت
```
