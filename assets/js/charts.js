/**
 * نمودارهای سبک و بدون وابستگی برای داشبورد تحلیلی.
 * خروجی: جاوااسکریپت خالص با خروجی داخلی (بدون کتابخانهٔ بیرونی) تا در
 * هاست اشتراکی و حالت آفلاینِ اپ بدون هیچ منبع خارجی کار کند.
 */
(function () {
    'use strict';

    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function faNum(value) {
        return String(value).replace(/\d/g, function (d) { return FA_DIGITS[+d]; });
    }

    function faMoney(value) {
        var n = Math.round(Number(value) || 0);
        var s = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '،');
        return faNum(s);
    }

    /** کوتاه‌کردن عدد برای محور (هزار → «۱۲ه») */
    function faShort(value) {
        var n = Number(value) || 0;
        if (Math.abs(n) >= 1000000000) { return faNum((n / 1000000000).toFixed(1)) + ' میلیارد'; }
        if (Math.abs(n) >= 1000000) { return faNum((n / 1000000).toFixed(1)) + ' میلیون'; }
        if (Math.abs(n) >= 1000) { return faNum(Math.round(n / 1000)) + ' هزار'; }
        return faNum(n);
    }

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fallback;
    }

    /**
     * نمودار ستونی دوطرفهٔ درآمد/هزینهٔ ماهانه
     * cfg: { el, months: [{label, income, expense}] }
     */
    function monthlyBars(cfg) {
        var el = cfg.el;
        var months = cfg.months || [];
        if (!el || !months.length) { return; }

        var W = 320, H = 150, padTop = 14, padBottom = 26, padSide = 6;
        var chartH = H - padTop - padBottom;
        var max = 0;
        months.forEach(function (m) { max = Math.max(max, m.income, m.expense); });
        if (max <= 0) { max = 1; }

        var gold = cssVar('--gold-primary', '#d4a373');
        var expenseColor = cssVar('--chart-expense', '#e07a5f');
        var axis = cssVar('--chart-axis', '#94a3b8');

        var groupW = (W - padSide * 2) / months.length;
        var barW = Math.min(16, groupW / 3);
        var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;" role="img" aria-label="نمودار درآمد و هزینه ماهانه">';

        // خطوط راهنمای افقی
        for (var g = 0; g <= 3; g++) {
            var gy = padTop + (chartH / 3) * g;
            svg += '<line x1="' + padSide + '" y1="' + gy + '" x2="' + (W - padSide) + '" y2="' + gy + '" stroke="' + axis + '" stroke-opacity=".18" stroke-width="1"/>';
        }

        months.forEach(function (m, i) {
            var cx = padSide + groupW * i + groupW / 2;
            var hI = (m.income / max) * chartH;
            var hE = (m.expense / max) * chartH;
            var xI = cx - barW - 1.5;
            var xE = cx + 1.5;
            var yI = padTop + chartH - hI;
            var yE = padTop + chartH - hE;
            svg += '<rect x="' + xI + '" y="' + yI + '" width="' + barW + '" height="' + Math.max(hI, 1.5) + '" rx="3" fill="' + gold + '">' +
                '<title>درآمد ' + m.label + ': ' + faMoney(m.income) + ' تومان</title></rect>';
            svg += '<rect x="' + xE + '" y="' + yE + '" width="' + barW + '" height="' + Math.max(hE, 1.5) + '" rx="3" fill="' + expenseColor + '" fill-opacity=".85">' +
                '<title>هزینه ' + m.label + ': ' + faMoney(m.expense) + ' تومان</title></rect>';
            svg += '<text x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle" font-size="9" fill="' + axis + '">' + m.label + '</text>';
        });

        svg += '</svg>';
        el.innerHTML =
            '<div class="bms-chart-legend">' +
            '<span class="bms-legend-dot" style="background:' + gold + '"></span> درآمد (وصولی)' +
            '<span class="bms-legend-dot" style="background:' + expenseColor + ';margin-right:14px;"></span> هزینه (صدورشده)' +
            '</div>' + svg;
    }

    /**
     * نمودار حلقه‌ای درصد وصولی
     * cfg: { el, percent }
     */
    function donut(cfg) {
        var el = cfg.el;
        if (!el) { return; }
        var pct = Math.max(0, Math.min(100, Number(cfg.percent) || 0));
        var R = 42, C = 2 * Math.PI * R;
        var filled = (pct / 100) * C;
        var gold = cssVar('--gold-primary', '#d4a373');
        var track = cssVar('--chart-track', '#e2e8f0');
        var text = cssVar('--chart-text', '#0f172a');

        el.innerHTML =
            '<svg viewBox="0 0 110 110" style="width:110px;height:110px;" role="img" aria-label="درصد وصولی ' + faNum(Math.round(pct)) + ' درصد">' +
            '<circle cx="55" cy="55" r="' + R + '" fill="none" stroke="' + track + '" stroke-width="10"/>' +
            '<circle cx="55" cy="55" r="' + R + '" fill="none" stroke="' + gold + '" stroke-width="10" stroke-linecap="round"' +
            ' stroke-dasharray="' + filled + ' ' + (C - filled) + '" transform="rotate(-90 55 55)" style="transition:stroke-dasharray .8s ease;"/>' +
            '<text x="55" y="52" text-anchor="middle" font-size="20" font-weight="800" fill="' + text + '">' + faNum(Math.round(pct)) + '٪</text>' +
            '<text x="55" y="70" text-anchor="middle" font-size="9" fill="' + cssVar('--chart-axis', '#94a3b8') + '">وصول شده</text>' +
            '</svg>';
    }

    /**
     * میله‌های افقی (مثل بدهکارترین واحدها)
     * cfg: { el, items: [{label, value}], color? , money? }
     */
    function hBars(cfg) {
        var el = cfg.el;
        var items = cfg.items || [];
        if (!el) { return; }
        if (!items.length) {
            el.innerHTML = '<p class="bms-chart-empty">موردی برای نمایش نیست 🎉</p>';
            return;
        }
        var max = Math.max.apply(null, items.map(function (x) { return Math.abs(x.value); }));
        if (max <= 0) { max = 1; }
        var color = cfg.color || cssVar('--gold-primary', '#d4a373');

        var html = '';
        items.forEach(function (x) {
            var w = Math.max(4, (Math.abs(x.value) / max) * 100);
            var valText = cfg.money === false ? faNum(x.value) : faMoney(x.value) + ' تومان';
            html +=
                '<div class="bms-hbar-row">' +
                '<span class="bms-hbar-label">' + x.label + '</span>' +
                '<span class="bms-hbar-track"><span class="bms-hbar-fill" style="width:' + w + '%;background:' + color + ';"></span></span>' +
                '<span class="bms-hbar-value">' + valText + '</span>' +
                '</div>';
        });
        el.innerHTML = html;
    }

    window.BmsCharts = {
        monthlyBars: monthlyBars,
        donut: donut,
        hBars: hBars,
        faNum: faNum,
        faMoney: faMoney,
        faShort: faShort
    };
})();
