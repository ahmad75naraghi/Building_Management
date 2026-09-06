<?php
/** تست اعتبارسنج ساده */
declare(strict_types=1);

use App\Utilities\Validator;

TestLog::suite('Validator — اعتبارسنجی');

TestLog::assertSame('داده معتبر خطا ندارد', [], Validator::validate(
    ['name' => 'علی رضایی', 'email' => 'a@b.com'],
    ['name' => 'required', 'email' => 'required|email']
));

$e = Validator::validate(['name' => ''], ['name' => 'required']);
TestLog::assertTrue('فیلد اجباری خالی خطا می‌دهد', isset($e['name']));

$e = Validator::validate(['email' => 'not-an-email'], ['email' => 'required|email']);
TestLog::assertTrue('ایمیل نامعتبر خطا می‌دهد', isset($e['email']));

$e = Validator::validate(['password' => '123'], ['password' => 'required|min:6']);
TestLog::assertTrue('حداقل طول رعایت نشده', isset($e['password']));

$e = Validator::validate(['password' => '123456'], ['password' => 'required|min:6']);
TestLog::assertSame('حداقل طول رعایت شده', [], $e);

$e = Validator::validate(['n' => '0'], ['n' => 'required']);
TestLog::assertSame('رشته «0» مقدار معتبر است', [], $e);
