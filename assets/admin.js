/* global TutorUserCleanup, jQuery */
( function ( $ ) {
	'use strict';

	var cfg = window.TutorUserCleanup || {};

	$( function () {
		injectNetworkCheckbox();
		bindConfirmOnDelete();
		bindPreview();
	} );

	/**
	 * The network (multisite) delete-users confirmation screen has no
	 * `delete_user_form` hook, so we inject the opt-in checkbox with JS as a
	 * best-effort fallback.
	 */
	function injectNetworkCheckbox() {
		if ( ! cfg.isNetwork ) {
			return;
		}
		// Only on the delete confirmation form (has the dodelete action).
		var $form = $( 'form' ).filter( function () {
			return /dodelete/.test( $( this ).find( 'input[name="action"]' ).val() || '' ) ||
				$( this ).find( 'input[name="dodelete"]' ).length > 0 ||
				/action=dodelete/.test( $( this ).attr( 'action' ) || '' );
		} ).first();

		if ( ! $form.length || $form.find( '#tutor_user_cleanup' ).length ) {
			return;
		}

		var label = ( cfg.i18n && cfg.i18n.networkLabel ) || 'Also wipe Tutor LMS learning data.';
		var $field = $(
			'<fieldset class="tutor-user-cleanup-fieldset">' +
			'<p><label><input type="checkbox" name="tutor_user_cleanup" id="tutor_user_cleanup" value="1" /> ' +
			'<strong></strong></label></p>' +
			'</fieldset>'
		);
		$field.find( 'strong' ).text( label );

		var $submit = $form.find( 'input[type="submit"], button[type="submit"]' ).first();
		if ( $submit.length ) {
			$submit.closest( 'p, .submit' ).before( $field );
		} else {
			$form.append( $field );
		}
	}

	/**
	 * Extra JS confirmation when the opt-in box is ticked and the form is
	 * submitted. This is a secondary safeguard, not the primary gate.
	 */
	function bindConfirmOnDelete() {
		$( document ).on( 'submit', 'form', function () {
			var $cb = $( this ).find( '#tutor_user_cleanup' );
			if ( $cb.length && $cb.is( ':checked' ) ) {
				return window.confirm( cfg.confirmText || 'This cannot be undone. Continue?' );
			}
			return true;
		} );
	}

	/**
	 * "Preview what will be removed" button -> AJAX dry-run counts.
	 */
	function bindPreview() {
		$( document ).on( 'click', '#tutor_user_cleanup_preview_btn', function ( e ) {
			e.preventDefault();

			var $btn = $( this );
			var users = $btn.data( 'users' );
			var $out = $( '#tutor_user_cleanup_preview' );
			var $spin = $( '#tutor_user_cleanup_spinner' );

			$spin.addClass( 'is-active' );
			$btn.prop( 'disabled', true );
			$out.empty();

			$.post( cfg.ajaxUrl, {
				action: 'tutor_user_cleanup_preview',
				nonce: cfg.previewNonce,
				users: String( users )
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					$out.html( '<p class="tutor-user-cleanup-warning">' + ( cfg.i18n.error || 'Error' ) + '</p>' );
					return;
				}
				$out.html( renderCounts( resp.data.counts || {} ) );
			} ).fail( function () {
				$out.html( '<p class="tutor-user-cleanup-warning">' + ( cfg.i18n.error || 'Error' ) + '</p>' );
			} ).always( function () {
				$spin.removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
			} );
		} );
	}

	function renderCounts( counts ) {
		var i18n = cfg.i18n || {};
		var order = [ 'enrollments', 'courses_reset', 'course_completions', 'lesson_completions', 'quiz_attempts', 'gradebook_results' ];
		var any = false;
		var rows = '';

		order.forEach( function ( key ) {
			var val = parseInt( counts[ key ] || 0, 10 );
			if ( val > 0 ) {
				any = true;
			}
			rows += '<tr><td>' + escapeHtml( i18n[ key ] || key ) + '</td><td><strong>' + val + '</strong></td></tr>';
		} );

		if ( ! any ) {
			return '<p>' + escapeHtml( i18n.nothing || 'No learning data found.' ) + '</p>';
		}

		return '<p>' + escapeHtml( i18n.previewFor || 'Learning data to be removed:' ) + '</p>' +
			'<table class="widefat striped" style="max-width:420px"><tbody>' + rows + '</tbody></table>';
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
}( jQuery ) );
