(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    function position(item) {
        var tip = item.querySelector('.cashback-coupons-icons__tooltip');
        if (!tip) {
            return;
        }
        var r = item.getBoundingClientRect();
        tip.style.left = (r.left + r.width / 2) + 'px';
        tip.style.top = r.top + 'px';
    }

    function handler(e) {
        var t = e.target;
        if (!t || !t.closest) {
            return;
        }
        var item = t.closest('.cashback-coupons-icons__item');
        if (item) {
            position(item);
        }
    }

    document.addEventListener('mouseover', handler, true);
    document.addEventListener('focusin', handler, true);
    document.addEventListener('touchstart', handler, { capture: true, passive: true });
})();
