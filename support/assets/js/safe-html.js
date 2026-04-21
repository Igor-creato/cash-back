(function(window) {
    'use strict';
    window.cashbackSafeHtml = function(dirty) {
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(dirty, {
                ALLOWED_TAGS: ['div', 'span', 'strong', 'em', 'b', 'i', 'u', 'p', 'h3', 'h4',
                               'a', 'br', 'hr', 'small', 'code', 'pre',
                               'ul', 'ol', 'li', 'nav',
                               'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
                               'textarea', 'button', 'input', 'label', 'select', 'option',
                               'img'],
                ALLOWED_ATTR: ['class', 'style', 'href', 'target', 'rel', 'id', 'title',
                               'type', 'rows', 'cols', 'maxlength', 'minlength',
                               'placeholder', 'multiple', 'accept', 'name', 'disabled',
                               'readonly', 'checked', 'selected', 'value',
                               'colspan', 'rowspan', 'scope', 'headers',
                               'role', 'aria-label', 'aria-hidden', 'aria-live',
                               'aria-expanded', 'aria-controls', 'aria-describedby',
                               'src', 'alt', 'width', 'height', 'loading'],
                ALLOW_DATA_ATTR: true
            });
        }
        return dirty;
    };
})(window);
