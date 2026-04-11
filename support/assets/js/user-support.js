(function($) {
    'use strict';

    $(document).ready(function() {

        // ========= Переключение вкладок =========
        $('.cashback-support-tab').on('click', function() {
            var tab = $(this).data('tab');

            // Активируем вкладку
            $('.cashback-support-tab').removeClass('active');
            $(this).addClass('active');

            // Показываем содержимое
            $('.cashback-support-tab-content').hide().removeClass('active');
            $('#tab-' + tab).show().addClass('active');

            // Скрываем детали тикета при переключении вкладок
            $('#support-ticket-detail').hide();
        });

        // ========= Валидация файлов (client-side) =========
        function validateFiles(fileInput, alertContainerId) {
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                return true;
            }

            var settings = cashback_support;
            var files = fileInput.files;

            if (files.length > parseInt(settings.max_files_per_message)) {
                showAlert(alertContainerId, 'error', 'Максимум ' + settings.max_files_per_message + ' файлов');
                return false;
            }

            var allowedExts = settings.allowed_extensions.split(',');
            var maxSizeBytes = parseInt(settings.max_file_size_kb) * 1024;

            for (var i = 0; i < files.length; i++) {
                var f = files[i];
                var ext = f.name.split('.').pop().toLowerCase();

                if (allowedExts.indexOf(ext) === -1) {
                    showAlert(alertContainerId, 'error', 'Файл с неразрешённым расширением, выберите другой');
                    return false;
                }

                if (f.size > maxSizeBytes) {
                    showAlert(alertContainerId, 'error', 'Файл большого размера, выберите другой');
                    return false;
                }
            }

            return true;
        }

        // ========= Создание тикета =========
        $('#support-create-form').on('submit', function(e) {
            e.preventDefault();

            var subject = $('#support-subject').val().trim();
            var priority = $('#support-priority').val();
            var message = $('#support-message').val().trim();

            // Сбрасываем подсветку ошибок
            $('#support-create-form .support-field-error').removeClass('support-field-error');

            if (!subject) {
                showAlert('support-create-alert', 'error', 'Заполните тему пожалуйста');
                $('#support-subject').addClass('support-field-error').focus();
                return;
            }
            if (!priority) {
                showAlert('support-create-alert', 'error', 'Выберите срочность');
                $('#support-priority').addClass('support-field-error').focus();
                return;
            }
            if (!message) {
                showAlert('support-create-alert', 'error', 'Введите сообщение пожалуйста');
                $('#support-message').addClass('support-field-error').focus();
                return;
            }
            if (message.length > 5000) {
                showAlert('support-create-alert', 'error', 'Сообщение слишком длинное (максимум 5000 символов).');
                $('#support-message').addClass('support-field-error').focus();
                return;
            }

            // Валидация файлов
            var fileInput = document.getElementById('support-files');
            if (!validateFiles(fileInput, 'support-create-alert')) {
                return;
            }

            var btn = $('#support-submit-btn');
            btn.prop('disabled', true).text('Отправка...');

            var fd = new FormData();
            fd.append('action', 'support_create_ticket');
            fd.append('nonce', cashback_support.create_nonce);
            fd.append('subject', subject);
            fd.append('priority', priority);
            fd.append('message', message);

            if (fileInput && fileInput.files.length > 0) {
                for (var i = 0; i < fileInput.files.length; i++) {
                    fd.append('support_files[]', fileInput.files[i]);
                }
            }

            $.ajax({
                url: cashback_support.ajax_url,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showAlert('support-create-alert', 'success', response.data.message);
                        $('#support-create-form')[0].reset();
                        $('#support-files-list').empty();
                        // Через 2 секунды переключаемся на вкладку истории и подгружаем первую страницу
                        setTimeout(function() {
                            $('.cashback-support-tab[data-tab="history"]').click();
                            loadTicketsPage(1);
                            btn.prop('disabled', false).text('Отправить');
                        }, 2000);
                    } else {
                        showAlert('support-create-alert', 'error', response.data.message || 'Ошибка при отправке, попробуйте еще раз');
                        btn.prop('disabled', false).text('Отправить');
                    }
                },
                error: function() {
                    showAlert('support-create-alert', 'error', 'Ошибка при отправке, попробуйте еще раз');
                    btn.prop('disabled', false).text('Отправить');
                }
            });
        });

        // ========= Загрузка тикета (клик по строке) =========
        $(document).on('click', '.support-ticket-row', function() {
            var $row = $(this);
            var ticketId = $row.data('ticket-id');
            // Снимаем выделение непрочитанного и убираем бейдж
            $row.removeClass('has-unread');
            $row.find('.support-unread-badge').remove();
            loadTicket(ticketId);
        });

        // ========= Назад к списку =========
        $(document).on('click', '#support-back-to-list', function(e) {
            e.preventDefault();
            $('#support-ticket-detail').hide();
            $('#tab-history').show();
            $('.cashback-support-tabs').show();
        });

        // ========= Ответ пользователя =========
        $(document).on('click', '#support-reply-btn', function() {
            var ticketId = $(this).data('ticket-id');
            var message = $('#support-reply-message').val().trim();

            if (!message) {
                showAlert('support-detail-alert', 'error', 'Введите сообщение пожалуйста');
                $('#support-reply-message').addClass('support-field-error').focus();
                return;
            }
            if (message.length > 5000) {
                showAlert('support-detail-alert', 'error', 'Сообщение слишком длинное (максимум 5000 символов).');
                $('#support-reply-message').addClass('support-field-error').focus();
                return;
            }

            // Валидация файлов
            var fileInput = document.getElementById('support-reply-files');
            if (!validateFiles(fileInput, 'support-detail-alert')) {
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Отправка...');

            var fd = new FormData();
            fd.append('action', 'support_user_reply');
            fd.append('nonce', cashback_support.reply_nonce);
            fd.append('ticket_id', ticketId);
            fd.append('message', message);

            if (fileInput && fileInput.files.length > 0) {
                for (var i = 0; i < fileInput.files.length; i++) {
                    fd.append('support_files[]', fileInput.files[i]);
                }
            }

            $.ajax({
                url: cashback_support.ajax_url,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#support-messages-list').append(cashbackSafeHtml(response.data.html));
                        $('#support-reply-message').val('');
                        if (fileInput) {
                            fileInput.value = '';
                        }
                        $('#support-reply-files-list').empty();
                        showAlert('support-detail-alert', 'success', 'Сообщение отправлено');
                    } else {
                        showAlert('support-detail-alert', 'error', response.data.message || 'Ошибка при отправке, попробуйте еще раз');
                    }
                    btn.prop('disabled', false).text('Ответить');
                },
                error: function() {
                    showAlert('support-detail-alert', 'error', 'Ошибка при отправке, попробуйте еще раз');
                    btn.prop('disabled', false).text('Ответить');
                }
            });
        });

        // ========= Скачивание вложения =========
        $(document).on('click', '.support-download-btn', function() {
            var btn = $(this);
            var id = btn.data('id');
            if (!id) { return; }

            btn.prop('disabled', true);

            var fd = new FormData();
            fd.append('action', 'support_download_file');
            fd.append('nonce', cashback_support.download_nonce);
            fd.append('id', id);

            fetch(cashback_support.ajax_url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('error');
                }
                var filename = 'file';
                var cd = response.headers.get('Content-Disposition');
                if (cd) {
                    var m = cd.match(/filename\*?=(?:UTF-8'')?([^;"\r\n]+)|filename="([^"]+)"/i);
                    if (m) { filename = decodeURIComponent(m[1] || m[2]); }
                }
                return response.blob().then(function(blob) {
                    return { blob: blob, filename: filename };
                });
            }).then(function(data) {
                var url = URL.createObjectURL(data.blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function() { URL.revokeObjectURL(url); }, 100);
                btn.prop('disabled', false);
            }).catch(function() {
                showAlert('support-detail-alert', 'error', 'Ошибка при скачивании файла');
                btn.prop('disabled', false);
            });
        });

        // ========= Закрытие тикета =========
        $(document).on('click', '#support-close-btn', function() {
            var btn = $(this);
            var ticketId = btn.data('ticket-id');
            btn.prop('disabled', true).text('Закрытие...');

            $.post(cashback_support.ajax_url, {
                action: 'support_user_close_ticket',
                nonce: cashback_support.close_nonce,
                ticket_id: ticketId
            }, function(response) {
                if (response.success) {
                    showAlert('support-detail-alert', 'success', 'Тикет закрыт');
                    // Обновляем бейдж статуса на «Закрыт»
                    var $badge = $('#support-ticket-detail-content .support-badge-open, #support-ticket-detail-content .support-badge-answered');
                    $badge.removeClass('support-badge-open support-badge-answered').addClass('support-badge-closed').text('Закрыт');
                    // Убираем форму ответа и кнопки действий
                    $('.support-ticket-actions').remove();
                    // Обновляем строку в списке тикетов
                    var $row = $('.support-ticket-row[data-ticket-id="' + ticketId + '"]');
                    $row.find('.support-badge-open, .support-badge-answered')
                        .removeClass('support-badge-open support-badge-answered')
                        .addClass('support-badge-closed').text('Закрыт');
                } else {
                    showAlert('support-detail-alert', 'error', response.data.message || 'Ошибка при выполнении');
                    btn.prop('disabled', false).text('Закрыть тикет');
                }
            }).fail(function() {
                showAlert('support-detail-alert', 'error', 'Ошибка при выполнении');
                btn.prop('disabled', false).text('Закрыть тикет');
            });
        });

        // ========= Превью выбранных файлов =========
        function initFilePreview(inputId, listId) {
            $(document).on('change', '#' + inputId, function() {
                var $list = $('#' + listId);
                $list.empty();
                var files = this.files;
                for (var i = 0; i < files.length; i++) {
                    var sizeKB = Math.round(files[i].size / 1024);
                    $list.append(
                        '<div class="support-file-item">' +
                        '<span class="support-file-name">' + escapeHtml(files[i].name) + '</span> ' +
                        '<span class="support-file-size">(' + sizeKB + ' КБ)</span>' +
                        '</div>'
                    );
                }
            });
        }

        if (parseInt(cashback_support.attachments_enabled)) {
            initFilePreview('support-files', 'support-files-list');
            initFilePreview('support-reply-files', 'support-reply-files-list');
        }

        // ========= Пагинация истории тикетов (AJAX) =========
        function buildTicketsPagination(currentPage, totalPages) {
            if (totalPages <= 1) {
                return '';
            }

            var range = 2;
            var edge = 2;
            var pagesSet = {};
            var i;

            for (i = 1; i <= Math.min(edge, totalPages); i++) {
                pagesSet[i] = true;
            }
            for (i = Math.max(1, currentPage - range); i <= Math.min(totalPages, currentPage + range); i++) {
                pagesSet[i] = true;
            }
            for (i = Math.max(1, totalPages - edge + 1); i <= totalPages; i++) {
                pagesSet[i] = true;
            }

            var pages = Object.keys(pagesSet).map(Number).sort(function (a, b) { return a - b; });

            var html = '<nav class="woocommerce-pagination"><ul class="page-numbers">';

            if (currentPage > 1) {
                html += '<li><a href="#" class="page-numbers prev" data-page="' + (currentPage - 1) + '">&lsaquo;</a></li>';
            }

            var prev = 0;
            for (i = 0; i < pages.length; i++) {
                var page = pages[i];
                if (prev && page - prev > 1) {
                    html += '<li><span class="page-numbers dots">&hellip;</span></li>';
                }
                var cls = (page === currentPage) ? 'current' : '';
                html += '<li><a href="#" class="page-numbers ' + cls + '" data-page="' + page + '">' + page + '</a></li>';
                prev = page;
            }

            if (currentPage < totalPages) {
                html += '<li><a href="#" class="page-numbers next" data-page="' + (currentPage + 1) + '">&rsaquo;</a></li>';
            }

            html += '</ul></nav>';
            return html;
        }

        function loadTicketsPage(page) {
            $.ajax({
                url: cashback_support.ajax_url,
                type: 'POST',
                data: {
                    action: 'support_load_tickets_page',
                    nonce: cashback_support.tickets_page_nonce,
                    page: page
                },
                success: function (response) {
                    if (response.success) {
                        $('#support-tickets-container').html(response.data.html);
                        $('#support-pagination').html(
                            buildTicketsPagination(response.data.current_page, response.data.total_pages)
                        );
                    }
                }
            });
        }

        $(document).on('click', '#support-pagination .page-numbers[data-page]', function (e) {
            e.preventDefault();
            var page = $(this).data('page');
            if (page) {
                loadTicketsPage(page);
            }
        });

        // ========= Вспомогательные функции =========

        function loadTicket(ticketId, callback) {
            $('#tab-history').hide();
            $('.cashback-support-tabs').hide();
            $('#support-ticket-detail').show();
            $('#support-ticket-detail-content').html('<p>Загрузка...</p>');

            $.post(cashback_support.ajax_url, {
                action: 'support_load_ticket',
                nonce: cashback_support.load_nonce,
                ticket_id: ticketId
            }, function(response) {
                if (response.success) {
                    $('#support-ticket-detail-content').html(cashbackSafeHtml(response.data.html));
                    updateMenuBadge(response.data.unread_total);
                    if (typeof callback === 'function') {
                        callback();
                    }
                } else {
                    showAlert('support-detail-alert', 'error', 'Ошибка загрузки тикета');
                    $('#support-ticket-detail-content').html('<p>Ошибка загрузки тикета</p>');
                }
            }).fail(function() {
                $('#support-ticket-detail-content').html('<p>Ошибка загрузки тикета</p>');
            });
        }

        function showAlert(containerId, type, message) {
            var cssClass = type === 'success' ? 'support-alert-success' : 'support-alert-error';
            var $container = $('#' + containerId);
            $container.html('<div class="support-alert ' + cssClass + '">' + escapeHtml(message) + '</div>').show();

            if (type === 'success') {
                setTimeout(function() {
                    $container.fadeOut();
                }, 5000);
            }
        }

        function updateMenuBadge(count) {
            var $style = $('#cashback-support-menu-badge-style');
            if (count > 0) {
                var css = '.woocommerce-MyAccount-navigation-link--cashback-support a::after {' +
                    "content: '" + parseInt(count) + "';" +
                    'display: inline-block; min-width: 18px; height: 18px; line-height: 18px;' +
                    'padding: 0 5px; border-radius: 50%; background: #f44336;' +
                    'color: #fff !important; font-size: 11px; font-weight: bold;' +
                    'text-align: center; margin-left: 6px; vertical-align: middle;}';
                if ($style.length) {
                    $style.html(css);
                } else {
                    $('head').append('<style id="cashback-support-menu-badge-style">' + css + '</style>');
                }
            } else {
                $style.remove();
            }
            // Обновляем бейдж на вкладке «История тикетов»
            updateTabBadge(count);
        }

        function updateTabBadge(count) {
            var $badge = $('#support-tab-unread-badge');
            if (count > 0) {
                if ($badge.length) {
                    $badge.text(parseInt(count));
                } else {
                    $('.cashback-support-tab[data-tab="history"]').append(
                        '<span class="support-tab-badge" id="support-tab-unread-badge">' + parseInt(count) + '</span>'
                    );
                }
            } else {
                $badge.remove();
            }
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // Снимаем подсветку ошибки при вводе/выборе
        $(document).on('input change', '.support-field-error', function() {
            $(this).removeClass('support-field-error');
        });
    });

})(jQuery);
