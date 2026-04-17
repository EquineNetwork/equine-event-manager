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

	function setStatus( $picker, message ) {
		$picker.find( '.en-event-picker__status' ).text( message || '' );
	}

	function clearResults( $picker ) {
		$picker.find( '.en-event-picker__results' ).empty().hide();
	}

	function renderResults( $picker, results ) {
		var $results = $picker.find( '.en-event-picker__results' );
		$results.empty();

		if ( ! results.length ) {
			setStatus( $picker, enEventManagerAdmin.strings.noResults );
			$results.hide();
			return;
		}

		results.forEach( function ( event ) {
			$( '<li />', {
				'class': 'en-event-picker__result',
				'role': 'option',
				'data-id': event.id,
				'data-label': event.label,
				'text': event.label
			} ).appendTo( $results );
		} );

		setStatus( $picker, '' );
		$results.show();
	}

	function searchEvents( $picker ) {
		var term = $picker.find( '.en-event-picker__search' ).val();

		setStatus( $picker, enEventManagerAdmin.strings.searching );

		$.ajax( {
			url: enEventManagerAdmin.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: {
				action: 'en_event_manager_search_tec_events',
				nonce: enEventManagerAdmin.nonce,
				term: term
			}
		} ).done( function ( response ) {
			if ( response && response.success && response.data && response.data.results ) {
				renderResults( $picker, response.data.results );
				return;
			}

			setStatus( $picker, enEventManagerAdmin.strings.error );
			clearResults( $picker );
		} ).fail( function () {
			setStatus( $picker, enEventManagerAdmin.strings.error );
			clearResults( $picker );
		} );
	}

	function syncEventSourceRows() {
		var source = $( '#en_event_source' ).val();
		$( '.en-tec-event-row' ).toggle( source === 'tec' );
	}

	$( function () {
		var $picker = $( '.en-event-picker' );

		if ( ! $picker.length ) {
			return;
		}

		var debouncedSearch = debounce( function () {
			searchEvents( $picker );
		}, 250 );

		$( '#en_event_source' ).on( 'change', syncEventSourceRows );
		syncEventSourceRows();

		$picker.on( 'focus', '.en-event-picker__search', function () {
			searchEvents( $picker );
		} );

		$picker.on( 'input', '.en-event-picker__search', function () {
			var selectedLabel = $picker.attr( 'data-selected-label' ) || '';

			if ( $( this ).val() !== selectedLabel ) {
				$( '#en_event_id' ).val( '0' );
			}

			debouncedSearch();
		} );

		$picker.on( 'click', '.en-event-picker__result', function () {
			var eventId = $( this ).data( 'id' );
			var label = $( this ).data( 'label' );

			$( '#en_event_id' ).val( eventId );
			$picker.find( '.en-event-picker__search' ).val( label );
			$picker.attr( 'data-selected-label', label );
			setStatus( $picker, '' );
			clearResults( $picker );
		} );

		$picker.on( 'click', '.en-event-picker__clear', function () {
			$( '#en_event_id' ).val( '0' );
			$picker.find( '.en-event-picker__search' ).val( '' ).trigger( 'focus' );
			$picker.attr( 'data-selected-label', '' );
		} );

		$( document ).on( 'click', function ( event ) {
			if ( ! $( event.target ).closest( '.en-event-picker' ).length ) {
				clearResults( $picker );
			}
		} );
	} );
}( jQuery ) );
