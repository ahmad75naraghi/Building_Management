/**
 * ==================== تقویم شمسی (Jalali Date Picker) ====================
 * فیلدهای تاریخ با نشانهٔ  data-jalali-date  به‌صورت خودکار به انتخابگر
 * تاریخ شمسی تبدیل می‌شوند:
 *   - ورودی اصلی (همان نام فرم) مقدار میلادی «Y-m-d» را برای سرور نگه می‌دارد
 *   - ورودی نمایشی، تاریخ شمسی را با اعداد فارسی نشان می‌دهد
 * بدون هیچ وابستگی خارجی؛ الگوریتم تبدیل از jalaali-js.
 */
(function () {
    'use strict';

    /* ---------------- تبدیل تاریخ ---------------- */

    function div(a, b) { return ~~(a / b); }
    function mod(a, b) { return a - ~~(a / b) * b; }

    var BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
        1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    function jalCal(jy) {
        var bl = BREAKS.length, gy = jy + 621, leapJ = -14, jp = BREAKS[0], jm, jump, leap, leapG, march, n, i;
        for (i = 1; i < bl; i += 1) {
            jm = BREAKS[i];
            jump = jm - jp;
            if (jy < jm) { break; }
            leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
            jp = jm;
        }
        n = jy - jp;
        leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
        if (mod(jump, 33) === 4 && jump - n === 4) { leapJ += 1; }
        leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
        march = 20 + leapJ - leapG;
        if (jump - n < 6) { n = n - jump + div(jump + 4, 33) * 33; }
        leap = mod(mod(n + 1, 33) - 1, 4);
        if (leap === -1) { leap = 4; }
        return { leap: leap, gy: gy, march: march };
    }

    function g2d(gy, gm, gd) {
        var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4)
            + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
        d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
        return d;
    }

    function d2g(jdn) {
        var j = 4 * jdn + 139361631;
        j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        var i = div(mod(j, 1461), 4) * 5 + 308;
        var gd = div(mod(i, 153), 5) + 1;
        var gm = mod(div(i, 153), 12) + 1;
        var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
        return { gy: gy, gm: gm, gd: gd };
    }

    function j2d(jy, jm, jd) {
        var r = jalCal(jy);
        return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    }

    function d2j(jdn) {
        var gy = d2g(jdn).gy, jy = gy - 621, r = jalCal(jy), jdn1f = g2d(gy, 3, r.march), k = jdn - jdn1f, jd, jm;
        if (k >= 0) {
            if (k <= 185) {
                return { jy: jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 };
            }
            k -= 186;
        } else {
            jy -= 1;
            k += 179;
            if (r.leap === 1) { k += 1; }
        }
        jm = 7 + div(k, 30);
        jd = mod(k, 30) + 1;
        return { jy: jy, jm: jm, jd: jd };
    }

    function gregorianToJalali(gStr) {
        var p = String(gStr).slice(0, 10).split('-');
        if (p.length !== 3) { return null; }
        try {
            return d2j(g2d(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10)));
        } catch (e) {
            return null;
        }
    }

    function jalaliToGregorian(jy, jm, jd) {
        var g = d2g(j2d(jy, jm, jd));
        return g.gy + '-' + String(g.gm).padStart(2, '0') + '-' + String(g.gd).padStart(2, '0');
    }

    function jalaliMonthLength(jy, jm) {
        if (jm <= 6) { return 31; }
        if (jm <= 11) { return 30; }
        /* در الگوریتم، صفر یعنی سال کبیسه است */
        return jalCal(jy).leap === 0 ? 30 : 29;
    }

    /* ---------------- ظواهر ---------------- */

    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var FA = '۰۱۲۳۴۵۶۷۸۹';

    function faDigits(v) {
        return String(v).replace(/[0-9]/g, function (d) { return FA[+d]; });
    }

    function jalaliToday() {
        var now = new Date();
        return d2j(g2d(now.getFullYear(), now.getMonth() + 1, now.getDate()));
    }

    function firstWeekdayOfMonth(jy, jm) {
        /* شنبه = 0 … جمعه = 6 */
        var wd = mod(j2d(jy, jm, 1) + 2, 7); // j2d از دوشنبه شروع می‌شود؛ +۲ برای شنبه
        return mod(wd, 7);
    }

    /* ---------------- ساخت ویجت ---------------- */

    function pad(n) { return String(n).padStart(2, '0'); }

    function enhance(input) {
        if (input.getAttribute('data-jalali-enhanced')) { return; }
        input.setAttribute('data-jalali-enhanced', '1');

        var wrap = document.createElement('div');
        wrap.style.position = 'relative';
        input.insertAdjacentElement('afterend', wrap);
        input.type = 'hidden';

        var display = document.createElement('input');
        display.type = 'text';
        display.readOnly = true;
        display.className = input.className;
        display.placeholder = input.placeholder || '۱۴۰۴/۰۱/۰۱';
        display.setAttribute('autocomplete', 'off');
        display.dir = 'ltr';
        display.style.textAlign = 'center';
        display.style.cursor = 'pointer';
        wrap.appendChild(display);

        var state = { open: false, view: null, popup: null };

        function syncDisplay() {
            var j = input.value ? gregorianToJalali(input.value) : null;
            display.value = j ? faDigits(j.jy + '/' + pad(j.jm) + '/' + pad(j.jd)) : '';
        }

        function setValue(gStr) {
            input.value = gStr || '';
            syncDisplay();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function closePopup() {
            if (state.popup) {
                state.popup.remove();
                state.popup = null;
            }
            state.open = false;
        }

        function openPopup() {
            if (state.open) { closePopup(); return; }
            closeAllPopups();

            var selected = input.value ? gregorianToJalali(input.value) : null;
            var today = jalaliToday();
            state.view = selected ? { jy: selected.jy, jm: selected.jm } : { jy: today.jy, jm: today.jm };

            var popup = document.createElement('div');
            popup.className = 'jalali-popup';
            state.popup = popup;
            wrap.appendChild(popup);
            state.open = true;
            render();

            setTimeout(function () {
                document.addEventListener('click', outsideClick, true);
            }, 0);
        }

        function outsideClick(e) {
            if (!state.open) { return; }
            if (popup && (popup.contains(e.target) || e.target === display)) { return; }
            closePopup();
            document.removeEventListener('click', outsideClick, true);
        }

        function render() {
            var popup = state.popup;
            if (!popup) { return; }
            var jy = state.view.jy, jm = state.view.jm;
            var selected = input.value ? gregorianToJalali(input.value) : null;
            var today = jalaliToday();
            var len = jalaliMonthLength(jy, jm);
            var start = firstWeekdayOfMonth(jy, jm);

            var html = '';
            html += '<div class="jalali-head">';
            html += '<button type="button" class="jalali-nav" data-nav="next" aria-label="ماه بعد">‹</button>';
            html += '<span class="jalali-title">' + MONTHS[jm - 1] + ' ' + faDigits(jy) + '</span>';
            html += '<button type="button" class="jalali-nav" data-nav="prev" aria-label="ماه قبل">›</button>';
            html += '</div>';
            html += '<div class="jalali-week">';
            for (var w = 0; w < 7; w++) { html += '<span>' + WEEKDAYS[w] + '</span>'; }
            html += '</div>';
            html += '<div class="jalali-grid">';
            for (var b = 0; b < start; b++) { html += '<span class="jalali-blank"></span>'; }
            for (var d = 1; d <= len; d++) {
                var cls = 'jalali-day';
                if (selected && selected.jy === jy && selected.jm === jm && selected.jd === d) { cls += ' selected'; }
                else if (today.jy === jy && today.jm === jm && today.jd === d) { cls += ' today'; }
                html += '<button type="button" class="' + cls + '" data-day="' + d + '">' + faDigits(d) + '</button>';
            }
            html += '</div>';
            html += '<div class="jalali-foot">';
            html += '<button type="button" class="jalali-clear">پاک کردن</button>';
            html += '<button type="button" class="jalali-today-btn">امروز</button>';
            html += '</div>';
            popup.innerHTML = html;

            popup.querySelectorAll('[data-nav]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dir = btn.getAttribute('data-nav') === 'next' ? 1 : -1;
                    var jm2 = jm + dir;
                    if (jm2 > 12) { jm2 = 1; jy += 1; }
                    if (jm2 < 1) { jm2 = 12; jy -= 1; }
                    state.view = { jy: jy, jm: jm2 };
                    render();
                });
            });
            popup.querySelectorAll('[data-day]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var d = parseInt(btn.getAttribute('data-day'), 10);
                    setValue(jalaliToGregorian(jy, jm, d));
                    closePopup();
                    document.removeEventListener('click', outsideClick, true);
                });
            });
            var todayBtn = popup.querySelector('.jalali-today-btn');
            if (todayBtn) {
                todayBtn.addEventListener('click', function () {
                    setValue(jalaliToGregorian(today.jy, today.jm, today.jd));
                    closePopup();
                    document.removeEventListener('click', outsideClick, true);
                });
            }
            var clearBtn = popup.querySelector('.jalali-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    setValue('');
                    closePopup();
                    document.removeEventListener('click', outsideClick, true);
                });
            }
        }

        display.addEventListener('click', openPopup);
        /* پیش‌پر کردن مودال‌ها (data-set-*) مقدار ورودی اصلی را عوض می‌کند */
        input.addEventListener('change', syncDisplay);
        syncDisplay();
    }

    function closeAllPopups() {
        document.querySelectorAll('.jalali-popup').forEach(function (p) { p.remove(); });
    }

    function init() {
        document.querySelectorAll('input[data-jalali-date]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* اگر مودالی بعداً محتوا گرفت، دوباره بررسی شود */
    window.jalaliPickerRefresh = init;
})();
