/*
 * Cashback admin — vanilla JS модал ручного создания транзакции из зависшего
 * approved claim (страница «Сверка баланса», подмодуль Группы 15).
 *
 * Контракт:
 *  - Кнопка-триггер: <button class="cashback-stuck-create-tx" data-claim-id="N">.
 *  - Разметка модала рендерится PHP'ом в footer'е страницы — JS только биндит
 *    обработчики и заполняет поля через два fetch'а.
 *  - cashbackStuckClaimTx (от wp_localize_script): { ajaxUrl, nonce, i18n }.
 *
 * AJAX:
 *  1) action=cashback_stuck_claim_load  → JSON pre-fill (claim + click_log).
 *  2) action=cashback_stuck_claim_create_tx → INSERT в cashback_transactions
 *     с server-side дедупом по request_id (UUIDv4 из crypto.randomUUID).
 */
( function () {
	'use strict';

	var cfg = window.cashbackStuckClaimTx || {};
	var COMISSION_REGEX = /^\d+(\.\d{1,2})?$/;

	var els = null;
	var lastFocused = null;
	var currentRequestId = null;

	function generateRequestId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function findElements() {
		if ( els ) {
			return els;
		}
		var backdrop = document.getElementById( 'cashback-stuck-tx-backdrop' );
		if ( ! backdrop ) {
			return null;
		}
		var dialog = backdrop.querySelector( '.cashback-stuck-tx-modal' );
		els = {
			backdrop: backdrop,
			dialog:   dialog,
			loading:  dialog.querySelector( '[data-role="loading"]' ),
			body:     dialog.querySelector( '[data-role="body"]' ),
			message:  dialog.querySelector( '[data-role="message"]' ),
			cancel:   dialog.querySelector( '[data-role="cancel"]' ),
			submit:   dialog.querySelector( '[data-role="submit"]' ),
			comission: dialog.querySelector( '#cashback-stuck-tx-comission' ),
			fundsReady: dialog.querySelector( '#cashback-stuck-tx-funds-ready' )
		};
		return els;
	}

	function bindOnce() {
		var e = findElements();
		if ( ! e || e.dialog.dataset.bound === '1' ) {
			return;
		}
		e.dialog.dataset.bound = '1';

		e.cancel.addEventListener( 'click', closeModal );
		e.submit.addEventListener( 'click', submit );

		e.backdrop.addEventListener( 'click', function ( ev ) {
			if ( ev.target === e.backdrop ) {
				closeModal();
			}
		} );

		e.dialog.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Escape' ) {
				ev.preventDefault();
				closeModal();
				return;
			}
			if ( ev.key === 'Tab' ) {
				trapFocus( e.dialog, ev );
			}
		} );
	}

	function trapFocus( container, event ) {
		var focusables = container.querySelectorAll(
			'input, select, textarea, button:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		if ( ! focusables.length ) {
			return;
		}
		var first = focusables[ 0 ];
		var last  = focusables[ focusables.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function showMessage( kind, text ) {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		e.message.className = 'cashback-stuck-tx-message is-' + kind;
		e.message.textContent = text;
		e.message.removeAttribute( 'hidden' );
	}

	function hideMessage() {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		e.message.setAttribute( 'hidden', '' );
		e.message.textContent = '';
	}

	function setBindValue( name, value ) {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		var nodes = e.dialog.querySelectorAll( '[data-bind="' + name + '"]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].textContent = ( value === null || value === undefined || value === '' ) ? '—' : String( value );
		}
	}

	function openModal( claimId ) {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		bindOnce();

		e.dialog.dataset.claimId = String( claimId );
		setBindValue( 'claim_id', claimId );
		[ 'user_id', 'click_id', 'merchant_name', 'order_id', 'order_value',
		  'order_date', 'partner', 'click_time', 'approved_at' ].forEach( function ( k ) {
			setBindValue( k, '' );
		} );

		e.body.setAttribute( 'hidden', '' );
		e.loading.removeAttribute( 'hidden' );
		e.comission.value = '';
		e.fundsReady.value = '';
		e.submit.disabled = true;
		e.cancel.disabled = false;
		hideMessage();
		currentRequestId = null;

		lastFocused = document.activeElement;
		e.backdrop.removeAttribute( 'hidden' );
		setTimeout( function () {
			e.dialog.focus();
		}, 30 );

		loadClaim( claimId );
	}

	function closeModal() {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		e.backdrop.setAttribute( 'hidden', '' );
		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
	}

	function loadClaim( claimId ) {
		var e = findElements();
		if ( ! e ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'cashback_stuck_claim_load' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'claim_id', String( claimId ) );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, json: j }; } );
			} )
			.then( function ( res ) {
				e.loading.setAttribute( 'hidden', '' );
				if ( res.ok && res.json && res.json.success ) {
					var d = res.json.data || {};
					setBindValue( 'user_id',       d.user_id );
					setBindValue( 'click_id',      d.click_id );
					setBindValue( 'merchant_name', d.merchant_name );
					setBindValue( 'order_id',      d.order_id );
					setBindValue( 'order_value',   d.order_value );
					setBindValue( 'order_date',    d.order_date );
					setBindValue( 'partner',       d.partner );
					setBindValue( 'click_time',    d.click_time );
					setBindValue( 'approved_at',   d.approved_at );
					e.body.removeAttribute( 'hidden' );
					e.submit.disabled = false;
					setTimeout( function () { e.comission.focus(); }, 30 );
				} else {
					var msg = ( res.json && res.json.data && res.json.data.message ) ||
						( ( cfg.i18n && cfg.i18n.genericError ) || 'Ошибка загрузки claim.' );
					showMessage( 'error', msg );
				}
			} )
			.catch( function () {
				e.loading.setAttribute( 'hidden', '' );
				showMessage( 'error', ( cfg.i18n && cfg.i18n.networkError ) || 'Ошибка сети. Повторите.' );
			} );
	}

	function submit() {
		var e = findElements();
		if ( ! e ) {
			return;
		}
		hideMessage();

		var claimId = parseInt( e.dialog.dataset.claimId, 10 );
		if ( ! claimId || claimId <= 0 ) {
			showMessage( 'error', ( cfg.i18n && cfg.i18n.genericError ) || 'Внутренняя ошибка.' );
			return;
		}

		var fundsReady = e.fundsReady.value;
		if ( fundsReady !== '0' && fundsReady !== '1' ) {
			showMessage( 'error', ( cfg.i18n && cfg.i18n.selectFundsReady ) || 'Выберите значение' );
			e.fundsReady.focus();
			return;
		}

		var comission = ( e.comission.value || '' ).trim();
		if ( ! COMISSION_REGEX.test( comission ) ) {
			showMessage( 'error', ( cfg.i18n && cfg.i18n.invalidComission ) ||
				'Некорректная комиссия.' );
			e.comission.focus();
			return;
		}
		if ( parseFloat( comission ) <= 0 ) {
			showMessage( 'error', ( cfg.i18n && cfg.i18n.comissionPositive ) ||
				'Комиссия должна быть больше нуля.' );
			e.comission.focus();
			return;
		}

		if ( ! currentRequestId ) {
			currentRequestId = generateRequestId();
		}

		e.submit.disabled = true;
		e.cancel.disabled = true;

		var body = new FormData();
		body.append( 'action', 'cashback_stuck_claim_create_tx' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'claim_id', String( claimId ) );
		body.append( 'comission', comission );
		body.append( 'funds_ready', fundsReady );
		body.append( 'request_id', currentRequestId );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, json: j, status: r.status }; } );
			} )
			.then( function ( res ) {
				if ( res.ok && res.json && res.json.success ) {
					var d = res.json.data || {};
					showMessage( 'success', d.message || 'Транзакция создана.' );
					// 1.5с чтобы админ увидел reference_id, потом reload — claim
					// исчезнет из таблицы по JOIN.
					setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else {
					var msg = ( res.json && res.json.data && res.json.data.message ) ||
						( ( cfg.i18n && cfg.i18n.genericError ) || 'Ошибка создания транзакции.' );
					showMessage( 'error', msg );
					e.submit.disabled = false;
					e.cancel.disabled = false;
					// Валидационная ошибка (4xx кроме 409 in_progress) — request_id
					// не сохранён сервером, генерируем новый при следующем submit'е,
					// иначе кэш-промах остался бы на следующий submit.
					if ( res.status && res.status >= 400 && res.status < 500 && res.status !== 409 ) {
						currentRequestId = null;
					}
				}
			} )
			.catch( function () {
				showMessage( 'error', ( cfg.i18n && cfg.i18n.networkError ) || 'Ошибка сети. Повторите.' );
				e.submit.disabled = false;
				e.cancel.disabled = false;
				// Сетевую ошибку retry'ем тем же request_id — server-side кэш отдаст
				// результат если запрос всё-таки дошёл.
			} );
	}

	// Делегированный listener — кнопки в таблице рендерятся серверно при первой загрузке.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.cashback-stuck-create-tx' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var cid = parseInt( btn.getAttribute( 'data-claim-id' ), 10 );
		if ( cid > 0 ) {
			openModal( cid );
		}
	} );

	window.CashbackStuckClaimTx = {
		open:  openModal,
		close: closeModal
	};
} )();
