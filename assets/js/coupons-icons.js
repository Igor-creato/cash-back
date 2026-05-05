(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.add('cashback-coupons-icons-js');

    var tooltip = null;

    function ensureTooltip() {
        if (tooltip && tooltip.isConnected) {
            return tooltip;
        }
        tooltip = document.createElement('span');
        tooltip.className = 'cashback-coupons-icons__floating';
        tooltip.setAttribute('aria-hidden', 'true');
        (document.body || document.documentElement).appendChild(tooltip);
        return tooltip;
    }

    function show(item) {
        var label = item.getAttribute('aria-label');
        if (!label) {
            return;
        }
        var t = ensureTooltip();
        t.textContent = label;
        var r = item.getBoundingClientRect();
        t.style.left = (r.left + r.width / 2) + 'px';
        t.style.top = r.top + 'px';
        t.classList.add('is-visible');
    }

    function hide() {
        if (tooltip) {
            tooltip.classList.remove('is-visible');
        }
    }

    function findItem(node) {
        return node && node.closest ? node.closest('.cashback-coupons-icons__item') : null;
    }

    document.addEventListener('mouseover', function (e) {
        var item = findItem(e.target);
        if (item) {
            show(item);
        }
    }, true);

    document.addEventListener('mouseout', function (e) {
        var fromItem = findItem(e.target);
        if (!fromItem) {
            return;
        }
        var toItem = findItem(e.relatedTarget);
        if (fromItem !== toItem) {
            hide();
        }
    }, true);

    document.addEventListener('focusin', function (e) {
        var item = findItem(e.target);
        if (item) {
            show(item);
        }
    }, true);

    document.addEventListener('focusout', function (e) {
        if (findItem(e.target)) {
            hide();
        }
    }, true);

    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide, true);
})();
