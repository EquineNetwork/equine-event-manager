( function ( $ ) {
	'use strict';

	function debounce( callback, delay ) {
		var timeout;

		return function () {
			var args = arguments;
			clearTimeout( timeout );
			timeout = setTimeout( function () {
				callback.apply( null, args );
			}, delay );
		};
	}

	function syncEventSourceRows() {
		var source = $( '#en_event_source' ).val();
		$( '.en-tec-event-row' ).toggle( source === 'tec' );
	}

	function formatEventResult( event ) {
		if ( event.loading ) {
			return event.text;
		}

		return event.text || '';
	}

	function requestEvents( term ) {
		return $.ajax( {
			url: enEventManagerAdmin.ajaxUrl,
			dataType: 'json',
			method: 'GET',
			data: {
				action: 'en_event_manager_search_tec_events',
				nonce: enEventManagerAdmin.nonce,
				term: term || ''
			}
		} );
	}

	function formatCurrencyInput( input ) {
		var value = String( $( input ).val() || '' ).replace( /[^0-9.]/g, '' );
		var parts = value.split( '.' );
		var amount;

		if ( parts.length > 2 ) {
			value = parts.shift() + '.' + parts.join( '' );
		}

		if ( '' === value ) {
			$( input ).val( '0.00' );
			return;
		}

		amount = parseFloat( value );

		if ( isNaN( amount ) ) {
			amount = 0;
		}

		$( input ).val( amount.toFixed( 2 ) );
	}

	function initializeFallbackPicker( $eventSelect ) {
		var selectedText = $eventSelect.find( 'option:selected' ).text();
		var selectedValue = $eventSelect.val();
		var $picker = $( '<div />', {
			'class': 'en-event-manager-event-picker'
		} );
		var $search = $( '<input />', {
			'type': 'search',
			'class': 'regular-text en-event-manager-event-picker__search',
			'placeholder': $eventSelect.data( 'placeholder' ) || enEventManagerAdmin.strings.placeholder,
			'autocomplete': 'off',
			'value': selectedValue && selectedValue !== '0' ? selectedText : ''
		} );
		var $clear = $( '<button />', {
			'type': 'button',
			'class': 'button en-event-manager-event-picker__clear',
			'text': 'Clear'
		} );
		var $status = $( '<div />', {
			'class': 'en-event-manager-event-picker__status',
			'aria-live': 'polite'
		} );
		var $results = $( '<ul />', {
			'class': 'en-event-manager-event-picker__results',
			'role': 'listbox'
		} );

		function setStatus( message ) {
			$status.text( message || '' );
		}

		function clearResults() {
			$results.empty().hide();
		}

		function selectEvent( eventId, eventTitle ) {
			if ( ! $eventSelect.find( 'option[value="' + eventId + '"]' ).length ) {
				$eventSelect.append( $( '<option />', {
					'value': eventId,
					'text': eventTitle
				} ) );
			}

			$eventSelect.val( String( eventId ) );
			$search.val( eventTitle );
			setStatus( '' );
			clearResults();
		}

		function renderResults( events ) {
			$results.empty();

			if ( ! events.length ) {
				setStatus( enEventManagerAdmin.strings.noResults );
				clearResults();
				return;
			}

			events.forEach( function ( event ) {
				$( '<li />', {
					'class': 'en-event-manager-event-picker__result',
					'role': 'option',
					'data-id': event.id,
					'data-title': event.text,
					'text': event.text
				} ).appendTo( $results );
			} );

			setStatus( '' );
			$results.show();
		}

		function searchEvents() {
			setStatus( enEventManagerAdmin.strings.searching );

			requestEvents( $search.val() ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.results ) {
					renderResults( response.data.results );
					return;
				}

				setStatus( enEventManagerAdmin.strings.error );
				clearResults();
			} ).fail( function () {
				setStatus( enEventManagerAdmin.strings.error );
				clearResults();
			} );
		}

		var debouncedSearch = debounce( searchEvents, 250 );

		$picker.append( $search, $clear, $status, $results );
		$eventSelect.after( $picker ).addClass( 'en-event-manager-select-hidden' );

		$search.on( 'focus', searchEvents );
		$search.on( 'input', function () {
			$eventSelect.val( '0' );
			debouncedSearch();
		} );
		$clear.on( 'click', function () {
			$eventSelect.val( '0' );
			$search.val( '' ).trigger( 'focus' );
		} );
		$results.on( 'click', '.en-event-manager-event-picker__result', function () {
			selectEvent( $( this ).data( 'id' ), $( this ).data( 'title' ) );
		} );
		$( document ).on( 'click', function ( event ) {
			if ( ! $( event.target ).closest( '.en-event-manager-event-picker' ).length ) {
				clearResults();
			}
		} );
	}

	$( function () {
		var $eventSource = $( '#en_event_source' );
		var $eventSelect = $( '#en_event_id' );

		$eventSource.on( 'change', syncEventSourceRows );
		syncEventSourceRows();

		$( '.en-event-manager-currency-field__input' ).on( 'blur', function () {
			formatCurrencyInput( this );
		} );

		if ( ! $eventSelect.length || ! $.fn.select2 ) {
			if ( $eventSelect.length ) {
				initializeFallbackPicker( $eventSelect );
			}
			return;
		}

		$eventSelect.select2( {
			ajax: {
				url: enEventManagerAdmin.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function ( params ) {
					return {
						action: 'en_event_manager_search_tec_events',
						nonce: enEventManagerAdmin.nonce,
						term: params.term || ''
					};
				},
				processResults: function ( response ) {
					if ( response && response.success && response.data && response.data.results ) {
						return {
							results: response.data.results
						};
					}

					return {
						results: []
					};
				},
				cache: true
			},
			allowClear: true,
			minimumInputLength: 0,
			placeholder: $eventSelect.data( 'placeholder' ) || enEventManagerAdmin.strings.placeholder,
			width: '360px',
			templateResult: formatEventResult,
			templateSelection: formatEventResult,
			language: {
				errorLoading: function () {
					return enEventManagerAdmin.strings.error;
				},
				inputTooShort: function () {
					return '';
				},
				noResults: function () {
					return enEventManagerAdmin.strings.noResults;
				},
				searching: function () {
					return enEventManagerAdmin.strings.searching;
				}
			}
		} );
	} );
}( jQuery ) );
