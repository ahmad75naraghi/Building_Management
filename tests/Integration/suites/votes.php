<?php
/** تست رأی‌گیری و نظرات: دسترسی مدیر، یک‌رأی بودن، بی‌طرفی نتایج، مالکیت نظرات */
declare(strict_types=1);

use App\Services\ExtraModulesService;

TestLog::suite('Votes & Reviews — دسترسی، یک‌رأی و مالکیت');

$svc = new ExtraModulesService();
$manager = make_user('09136000001', 'مدیر نظرسنجی');
$member = make_user('09136000002', 'ساکن یک');
$member2 = make_user('09136000003', 'ساکن دو');

TestLog::run('ایجاد رأی‌گیری فقط برای مدیر', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');

    TestLog::assertThrows('عضو عادی نمی‌تواند رأی‌گیری بسازد', fn() => $svc->createVote([
        'building_id' => $b, 'title' => 'رنگ نما', 'options' => ['بله', 'خیر'],
    ], $member), 'مدیر');

    $vote = $svc->createVote([
        'building_id' => $b, 'title' => 'رنگ نما', 'options' => ['بله', 'خیر'],
    ], $manager);
    TestLog::assertTrue('مدیر رأی‌گیری ساخت', $vote->id !== null);
    TestLog::assertSame('دو گزینه ثبت شد', 2, count($vote->options));
});

TestLog::run('پیش از شروع، رأی پذیرفته نمی‌شود', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');
    $vote = $svc->createVote([
        'building_id' => $b, 'title' => 'نگهبانی شبانه',
        'start_date' => date('Y-m-d H:i:s', time() + 3600),
        'options' => ['موافق', 'مخالف'],
    ], $manager);
    $opts = $vote->options;
    TestLog::assertThrows('رأی زودهنگام رد می‌شود', fn() => $svc->castVote((int) $vote->id, (int) $opts[0]['id'], $member), 'شروع');
});

TestLog::run('هر کاربر فقط یک رأی و فقط اعضا', function () use ($svc, $manager, $member, $member2) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');
    add_member($b, $member2, 'tenant');
    $vote = $svc->createVote([
        'building_id' => $b, 'title' => 'ایزوگام بام', 'options' => ['الف', 'ب'],
    ], $manager);
    $opts = $vote->options;

    $stranger = make_user('09136000009');
    TestLog::assertThrows('غیرعضو رأی نمی‌دهد', fn() => $svc->castVote((int) $vote->id, (int) $opts[0]['id'], $stranger), 'عضو این ساختمان');

    TestLog::assertTrue('رأی اول عضو ثبت شد', $svc->castVote((int) $vote->id, (int) $opts[0]['id'], $member));
    TestLog::assertThrows('رأی دوم همان عضو رد می‌شود', fn() => $svc->castVote((int) $vote->id, (int) $opts[1]['id'], $member), 'قبلاً');

    // نتیجه با تعداد و درصد درست
    $svc->castVote((int) $vote->id, (int) $opts[1]['id'], $member2);
    $results = $svc->getVoteResults((int) $vote->id, $member);
    TestLog::assertSame('دو رأی شمرده شد', 2, $results['total_votes']);
    TestLog::assertSame('درصد گزینه اول', 50.0, (float) $results['options'][0]['percentage']);
    TestLog::assertSame('گزینهٔ رأی خودم', (int) $opts[0]['id'], $results['my_option_id']);
});

TestLog::run('بستن رأی‌گیری فقط مدیر و پس از آن رأی/گزینه ممنوع', function () use ($svc, $manager, $member) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');
    $vote = $svc->createVote([
        'building_id' => $b, 'title' => 'سالن ورزش', 'options' => ['آری', 'نه'],
    ], $manager);
    $opts = $vote->options;

    TestLog::assertThrows('عضو نمی‌تواند ببندد', fn() => $svc->updateEntityStatus('votes', (int) $vote->id, 'closed', $member), 'مدیر');
    TestLog::assertTrue('مدیر بست', $svc->updateEntityStatus('votes', (int) $vote->id, 'closed', $manager));
    TestLog::assertThrows('رأی پس از بستن رد می‌شود', fn() => $svc->castVote((int) $vote->id, (int) $opts[0]['id'], $member), 'بسته');
    TestLog::assertThrows('گزینه جدید پس از بستن رد می‌شود', fn() => $svc->addVoteOptions((int) $vote->id, ['گزینه سه'], $manager), 'بسته');
});

TestLog::run('نظرات: سقف امتیاز، ویرایش مالک، حذف مدیر', function () use ($svc, $manager, $member, $member2) {
    $b = make_building($manager);
    add_member($b, $member, 'tenant');
    add_member($b, $member2, 'tenant');

    $review = $svc->createReview(['building_id' => $b, 'rating' => 9, 'review_text' => 'عالی'], $member);
    TestLog::assertSame('امتیاز به سقف ۵ مهار شد', 5, $review->rating);

    $updated = $svc->updateEntity('reviews', (int) $review->id, ['rating' => 3], $member);
    TestLog::assertSame('مالک ویرایش کرد', 3, (int) ($updated['rating'] ?? 0));

    TestLog::assertThrows('عضو دیگر نمی‌تواند ویرایش کند', fn() => $svc->updateEntity('reviews', (int) $review->id, ['rating' => 1], $member2), 'خودتان');
    TestLog::assertTrue('مدیر حذف کرد', $svc->deleteEntity('reviews', (int) $review->id, $manager));

    $list = $svc->listReviews($b, $manager);
    TestLog::assertSame('پس از حذف خالی است', 0, count($list));
});
