<?php
require_once 'includes/api_helper.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

// جستجوی نرمال‌شده (بدون حساسیت به فاصلهٔ اضافی)
$q_norm = mb_strtolower(preg_replace('/\s+/u', ' ', $q));

$bms_match = static function ($haystack) use ($q_norm): bool {
    if ($q_norm === '') {
        return false;
    }
    $h = mb_strtolower(preg_replace('/\s+/u', ' ', (string) $haystack));
    return $h !== '' && str_contains($h, $q_norm);
};

$results = [
    'units' => [],
    'members' => [],
    'costs' => [],
    'payments' => [],
    'tickets' => [],
    'announcements' => [],
    'documents' => [],
];
$total_hits = 0;

if ($q !== '' && $building_id > 0) {
    $units = [];
    $r = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (!empty($r['success'])) {
        $units = $r['data'] ?? [];
    }
    foreach ($units as $u) {
        $unit_text = ($u['unit_number'] ?? '') . ' ' . ($u['owner_name'] ?? '') . ' ' . ($u['floor_name'] ?? '') . ' ' . ($u['block_name'] ?? '');
        if ($bms_match($unit_text)) {
            $results['units'][] = $u;
        }
    }

    $r = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $m) {
            $member_text = ($m['name'] ?? '') . ' ' . ($m['mobile'] ?? '') . ' ' . ($m['unit_number'] ?? '');
            if ($bms_match($member_text)) {
                $results['members'][] = $m;
            }
        }
    }

    $r = callAPI('GET', '/costs', ['building_id' => $building_id]);
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $c) {
            if ($bms_match(($c['title'] ?? '') . ' ' . ($c['description'] ?? ''))) {
                $results['costs'][] = $c;
            }
        }
    }

    $r = callAPI('GET', '/payments', ['building_id' => $building_id]);
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $p) {
            if ($bms_match(($p['unit_number'] ?? '') . ' ' . ($p['user_name'] ?? '') . ' ' . ($p['status'] ?? ''))) {
                $results['payments'][] = $p;
            }
        }
    }

    $r = callAPI('GET', '/tickets', ['building_id' => $building_id]);
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $t) {
            if ($bms_match(($t['subject'] ?? '') . ' ' . ($t['title'] ?? '') . ' ' . ($t['description'] ?? ''))) {
                $results['tickets'][] = $t;
            }
        }
    }

    $r = callAPI('GET', '/announcements', ['building_id' => $building_id]);
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $a) {
            if ($bms_match(($a['title'] ?? '') . ' ' . ($a['content'] ?? ''))) {
                $results['announcements'][] = $a;
            }
        }
    }

    $r = callAPI('GET', '/documents', ['building_id' => $building_id]);
    if (!empty($r['success'])) {
        foreach ($r['data'] ?? [] as $d) {
            if ($bms_match(($d['title'] ?? '') . ' ' . ($d['name'] ?? '') . ' ' . ($d['description'] ?? ''))) {
                $results['documents'][] = $d;
            }
        }
    }

    foreach ($results as $group) {
        $total_hits += count($group);
    }
}

$page_title = 'جستجو';
$page_hint  = 'جستجوی یک‌جا بین واحدها، اعضا، هزینه‌ها، تیکت‌ها، اعلان‌ها و اسناد ساختمان.';
$nav_active = 'none';
require_once 'includes/header.php';
?>

        <form method="get" action="search.php" class="search-form" role="search">
            <input type="hidden" name="building_id" value="<?= $building_id ?>">
            <input type="search" name="q" class="form-input search-input" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="جستجوی واحد، ساکن، هزینه، تیکت…" aria-label="عبارت جستجو" autofocus>
            <button type="submit" class="btn btn-primary search-submit">جستجو</button>
        </form>

        <?php if ($q === ''): ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                عبارت مورد نظر را بنویسید؛ مثلاً شماره واحد، نام ساکن یا عنوان هزینه.
            </div>
        <?php elseif ($total_hits === 0): ?>
            <div class="empty-state">
                <div class="empty-icon">🤷</div>
                نتیجه‌ای برای «<?= htmlspecialchars($q, ENT_QUOTES) ?>» پیدا نشد.
            </div>
        <?php else: ?>
            <p class="search-summary">✅ <?= fa_digits($total_hits) ?> نتیجه برای «<?= htmlspecialchars($q, ENT_QUOTES) ?>»</p>

            <?php if (!empty($results['units'])): ?>
                <h2 class="section-title">🏠 واحدها (<?= fa_digits(count($results['units'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['units'], 0, 20) as $u): ?>
                        <a href="units.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title">واحد <?= htmlspecialchars((string) ($u['unit_number'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="search-result-sub"><?= htmlspecialchars(trim(($u['floor_name'] ?? '') . ' — ' . ($u['owner_name'] ?? 'بدون مالک'), ' —'), ENT_QUOTES) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['members'])): ?>
                <h2 class="section-title">👥 اعضا (<?= fa_digits(count($results['members'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['members'], 0, 20) as $m): ?>
                        <a href="members.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title"><?= htmlspecialchars((string) ($m['name'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="search-result-sub"><?= htmlspecialchars(trim(($m['mobile'] ?? '') . ' — واحد ' . ($m['unit_number'] ?? '؟')), ' —', ENT_QUOTES) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['costs'])): ?>
                <h2 class="section-title">💰 هزینه‌ها (<?= fa_digits(count($results['costs'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['costs'], 0, 20) as $c): ?>
                        <a href="costs.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title"><?= htmlspecialchars((string) ($c['title'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="search-result-sub"><?= fa_digits(number_format((float) ($c['amount'] ?? 0))) ?> تومان</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['payments'])): ?>
                <h2 class="section-title">🧾 پرداخت‌ها (<?= fa_digits(count($results['payments'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['payments'], 0, 20) as $p): ?>
                        <a href="costs.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title">پرداخت واحد <?= htmlspecialchars((string) ($p['unit_number'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="search-result-sub"><?= fa_digits(number_format((float) ($p['amount'] ?? 0))) ?> تومان</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['tickets'])): ?>
                <h2 class="section-title">🎫 تیکت‌ها (<?= fa_digits(count($results['tickets'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['tickets'], 0, 20) as $t): ?>
                        <a href="tickets.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title"><?= htmlspecialchars((string) (($t['subject'] ?? '') ?: ($t['title'] ?? '')), ENT_QUOTES) ?></span>
                            <span class="search-result-sub">وضعیت: <?= htmlspecialchars((string) ($t['status'] ?? ''), ENT_QUOTES) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['announcements'])): ?>
                <h2 class="section-title">📢 اعلان‌ها (<?= fa_digits(count($results['announcements'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['announcements'], 0, 20) as $a): ?>
                        <a href="announcements.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title"><?= htmlspecialchars((string) ($a['title'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="search-result-sub"><?= htmlspecialchars(mb_substr((string) ($a['content'] ?? ''), 0, 60), ENT_QUOTES) ?>…</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['documents'])): ?>
                <h2 class="section-title">📄 اسناد (<?= fa_digits(count($results['documents'])) ?>)</h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($results['documents'], 0, 20) as $d): ?>
                        <a href="documents.php?building_id=<?= $building_id ?>" class="search-result-card">
                            <span class="search-result-title"><?= htmlspecialchars((string) (($d['title'] ?? '') ?: ($d['name'] ?? '')), ENT_QUOTES) ?></span>
                            <span class="search-result-sub">مشاهده در بخش اسناد</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
