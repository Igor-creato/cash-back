/*
 * Cashback admin — vanilla JS модал ручной корректировки баланса (Группа 15, S2.B).
 *
 * Публичный API:
 *   window.CashbackBalanceAdjust.open({
 *     userId:          <int>,           // обязательный
 *     prefillAmount:   '<string>'?,     // например '+10.00' или '-5.25'
 *     prefillReason:   '<string>'?,     // подсказка для поля reason
 *     onSuccess:       function(resp)?  // опциональный колбэк (для S3)
 *   });
 *
 * Локализация через window.cashbackBalanceAdjust (установлена wp_localize_script):
 *   - ajaxUrl, nonce, minReasonLength, amountPlaceholder, i18n-строки.
 *
 * Защита: focus-trap, Escape/Cancel/backdrop-click → close, Apply disabled
 * пока checkbox подтверждения не включён. AJAX — fetch POST на admin-ajax.php
 * с action=cashback_adjust_balance и request_id (UUIDv4 из crypto).
 */
( function () {
	'use strict';

	var cfg = window.cashbackBalanceAdjust || {};
	var AMOUNT_REGEX = /^[+-]?\d+(\.\d{1,2})?$/;

	var modal = null;
	var lastFocused = null;
	var onSuccessCallback = null;

	function generateRequestId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		// Fallback: RFC4122 v4 через random() — достаточно для server-side дедупа.
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function buildModal() {
		if ( modal ) {
			return modal;
		}
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'cashback-balance-adjust-backdrop';
		backdrop.setAttribute( 'hidden', '' );

		var dialog = document.createElement( 'div' );
		dialog.className = 'cashback-balance-adjust-modal';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'cashback-balance-adjust-title' );
		dialog.tabIndex = -1;

		dialog.innerHTML =
			'<h2 id="cashback-balance-adjust-title">' + escapeHtml( cfg.i18n && cfg.i18n.title ? cfg.i18n.title : 'Ручная корректировка баланса' ) + '</h2>' +
			'<div class="cashback-balance-adjust-user-info" data-role="user-info"></div>' +
			'<label for="cashback-balance-adjust-amount">' + escapeHtml( cfg.i18n && cfg.i18n.amountLabel ? cfg.i18n.amountLabel : 'Сумма корректировки' ) + '</label>' +
			'<input type="text" id="cashback-balance-adjust-amount" name="amount" ' +
				'inputmode="decimal" ' +
				'pattern="^[+-]?\\d+(\\.\\d{1,2})?$" ' +
				'placeholder="' + escapeHtml( cfg.amountPlaceholder || '+100.00 или -50.25' ) + '" required />' +
			'<p class="cashback-balance-adjust-hint">' + escapeHtml( cfg.i18n && cfg.i18n.amountHint ? cfg.i18n.amountHint : 'Знак +/- обязателен. Не более 2 знаков после точки.' ) + '</p>' +
			'<label for="cashback-balance-adjust-reason">' + escapeHtml( cfg.i18n && cfg.i18n.reasonLabel ? cfg.i18n.reasonLabel : 'Причина (минимум ' + ( cfg.minReasonLength || 20 ) + ' символов)' ) + '</label>' +
			'<textarea id="cashback-balance-adjust-reason" name="reason" minlength="' + ( cfg.minReasonLength || 20 ) + '" required></textarea>' +
			'<div class="cashback-balance-adjust-confirm">' +
				'<input type="checkbox" id="cashback-balance-adjust-confirm" />' +
				'<label for="cashback-balance-adjust-confirm" style="font-weight:normal;margin:0;">' +
					escapeHtml( cfg.i18n && cfg.i18n.confirm ? cfg.i18n.confirm : 'Я понимаю, что это запись в ledger с немедленным эффектом.' ) +
				'</label>' +
			'</div>' +
			'<div class="cashback-balance-adjust-message" hidden></div>' +
			'<div class="cashback-balance-adjust-actions">' +
				'<button type="button" class="button button-secondary" data-role="cancel">' +
					escapeHtml( cfg.i18n && cfg.i18n.cancel ? cfg.i18n.cancel : 'Отмена' ) +
				'</button>' +
				'<button type="button" class="button button-primary" data-role="apply" disabled>' +
					escapeHtml( cfg.i18n && cfg.i18n.apply ? cfg.i18n.apply : 'Применить' ) +
				'</button>' +
			'</div>';

		backdrop.appendChild( dialog );
		document.body.appendChild( backdrop );

		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop ) {
				closeModal();
			}
		} );

		var amountInput = dialog.querySelector( '#cashback-balance-adjust-amount' );
		var reasonInput = dialog.querySelector( '#cashback-balance-adjust-reason' );
		var confirmInput = dialog.querySelector( '#cashback-balance-adjust-confirm' );
		var applyBtn = dialog.querySelector( '[data-role="apply"]' );
		var cancelBtn = dialog.querySelector( '[data-role="cancel"]' );

		function updateApplyState() {
			applyBtn.disabled = ! confirmInput.checked;
		}
		confirmInput.addEventListener( 'change', updateApplyState );

		cancelBtn.addEventListener( 'click', closeModal );
		applyBtn.addEventListener( 'click', submit );

		dialog.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				closeModal();
				return;
			}
			if ( e.key === 'Tab' ) {
				trapFocus( dialog, e );
			}
		} );

		modal = {
			backdrop: backdrop,
			dialog: dialog,
			amountInput: amountInput,
			reasonInput: reasonInput,
			confirmInput: confirmInput,
			applyBtn: applyBtn,
			cancelBtn: cancelBtn,
			userInfo: dialog.querySelector( '[data-role="user-info"]' ),
			message: dialog.querySelector( '.cashback-balance-adjust-message' )
		};
		return modal;
	}

	function trapFocus( container, event ) {
		var focusables = container.querySelectorAll(
			'input, textarea, button:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		if ( ! focusables.length ) {
			return;
		}
		var first = focusables[ 0 ];
		var last = focusables[ focusables.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function showMessage( kind, text ) {
		if ( ! modal ) {
			return;
		}
		modal.message.className = 'cashback-balance-adjust-message is-' + kind;
		modal.message.textContent = text;
		modal.message.removeAttribute( 'hidden' );
	}

	function hideMessage() {
		if ( modal && modal.message ) {
			modal.message.setAttribute( 'hidden', '' );
			modal.message.textContent = '';
		}
	}

	function openModal( options ) {
		options = options || {};
		var userId = parseInt( options.userId, 10 );
		if ( ! userId || userId <= 0 ) {
			window.console && console.error( 'CashbackBalanceAdjust.open: userId обязателен' );
			return;
		}

		onSuccessCallback = typeof options.onSuccess === 'function' ? options.onSuccess : null;

		var m = buildModal();
		m.amountInput.value = options.prefillAmount || '';
		m.reasonInput.value = options.prefillReason || '';
		m.confirmInput.checked = false;
		m.applyBtn.disabled = true;
		m.userInfo.textContent = ( cfg.i18n && cfg.i18n.forUser ? cfg.i18n.forUser : 'Пользователь ID:' ) + ' ' + userId;
		m.dialog.dataset.userId = String( userId );
		hideMessage();

		lastFocused = document.activeElement;
		m.backdrop.removeAttribute( 'hidden' );
		// Фокусируем amount после рендера кадра, иначе mobile-keyboard может не открыться.
		setTimeout( function () {
			m.amountInput.focus();
			m.amountInput.select();
		}, 30 );
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.backdrop.setAttribute( 'hidden', '' );
		onSuccessCallback = null;
		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
	}

	function submit() {
		if ( ! modal ) {
			return;
		}
		hideMessage();

		var userId = parseInt( modal.dialog.dataset.userId, 10 );
		var amount = ( modal.amountInput.value || '' ).trim();
		var reason = ( modal.reasonInput.value || '' ).trim();

		if ( ! AMOUNT_REGEX.test( amount ) ) {
			showMessage( 'error', ( cfg.i18n && cfg.i18n.invalidAmount ) ||
				'Введите сумму в формате +100.00 или -50.25.' );
			modal.amountInput.focus();
			return;
		}
		var minLen = cfg.minReasonLength || 20;
		if ( reason.length < minLen ) {
			showMessage( 'error',
				( ( cfg.i18n && cfg.i18n.reasonTooShort ) || 'Причина должна быть минимум {n} символов.' )
					.replace( '{n}', String( minLen ) )
			);
			modal.reasonInput.focus();
			return;
		}

		modal.applyBtn.disabled = true;
		modal.cancelBtn.disabled = true;

		var body = new FormData();
		body.append( 'action', 'cashback_adjust_balance' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'user_id', String( userId ) );
		body.append( 'amount', amount );
		body.append( 'reason', reason );
		body.append( 'request_id', generateRequestId() );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) {
				return r.json().then( function ( json ) {
					return { ok: r.ok, json: json };
				} );
			} )
			.then( function ( res ) {
				if ( res.ok && res.json && res.json.success ) {
					var data = res.json.data || {};
					showMessage( 'success',
						( ( cfg.i18n && cfg.i18n.success ) || 'Корректировка применена. Новый баланс: {b}.' )
							.replace( '{b}', data.new_available_balance || '' )
					);
					if ( onSuccessCallback ) {
						try {
							onSuccessCallback( data );
						} catch ( e ) {
							window.console && console.error( e );
						}
					}
					// Оставляем модал открытым на 1.5с чтобы пользователь увидел результат.
					setTimeout( closeModal, 1500 );
				} else {
					var msg = ( res.json && res.json.data && res.json.data.message ) ||
						( ( cfg.i18n && cfg.i18n.genericError ) || 'Ошибка применения корректировки.' );
					showMessage( 'error', msg );
					modal.applyBtn.disabled = false;
					modal.cancelBtn.disabled = false;
				}
			} )
			.catch( function () {
				showMessage( 'error', ( cfg.i18n && cfg.i18n.networkError ) || 'Ошибка сети. Повторите.' );
				modal.applyBtn.disabled = false;
				modal.cancelBtn.disabled = false;
			} );
	}

	window.CashbackBalanceAdjust = {
		open: openModal,
		close: closeModal
	};

	// Автопривязка кнопки «Корректировка» в таблицах (users-management, reconciliation).
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.cashback-adjust-balance-btn, .cashback-reconcil-adjust-btn' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var uid = parseInt( btn.getAttribute( 'data-user-id' ), 10 );
		if ( uid > 0 ) {
			openModal( { userId: uid } );
		}
	} );
} )();
