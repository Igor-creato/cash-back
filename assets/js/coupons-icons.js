(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.add('cashback-coupons-icons-js');

    var tooltip = null;
    var currentItem = null;
    var rafId = 0;

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

    function applyPosition(item) {
        var r = item.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) {
            return false;
        }
        tooltip.style.left = (r.left + r.width / 2) + 'px';
        tooltip.style.top = r.top + 'px';
        return true;
    }

    function show(item) {
        var label = item.getAttribute('aria-label');
        if (!label) {
            return;
        }
        var t = ensureTooltip();
        t.textContent = label;
        if (!applyPosition(item)) {
            return;
        }
        t.classList.add('is-visible');
        currentItem = item;
    }

    function hide() {
        if (tooltip) {
            tooltip.classList.remove('is-visible');
        }
        currentItem = null;
    }

    function reposition() {
        if (!currentItem || !tooltip) {
            return;
        }
        if (!currentItem.isConnected) {
            hide();
            return;
        }
        if (!applyPosition(currentItem)) {
            hide();
        }
    }

    function onScrollOrResize() {
        if (!currentItem) {
            return;
        }
        if (rafId) {
            return;
        }
        rafId = window.requestAnimationFrame(function () {
            rafId = 0;
            reposition();
        });
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

    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize, true);
})();
