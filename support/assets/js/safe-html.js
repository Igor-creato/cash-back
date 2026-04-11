(function(window) {
    'use strict';
    window.cashbackSafeHtml = function(dirty) {
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(dirty, {
                ALLOWED_TAGS: ['div', 'span', 'strong', 'p', 'h3', 'a', 'br', 'small',
                               'textarea', 'button', 'input', 'label'],
                ALLOWED_ATTR: ['class', 'style', 'href', 'target', 'id', 'data-ticket-id',
                               'type', 'rows', 'maxlength', 'placeholder', 'multiple',
                               'accept', 'name', 'disabled', 'value'],
                ALLOW_DATA_ATTR: true
            });
        }
        return dirty;
    };
})(window);
