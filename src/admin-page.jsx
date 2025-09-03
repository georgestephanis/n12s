/* global ajaxurl */

import { __, sprintf } from '@wordpress/i18n';
import apiRequest from '@wordpress/api-request';

const btnGetZips = document.querySelector( '#btnGetZips' );

btnGetZips.addEventListener( 'click', ( e ) => {
	e.preventDefault();
	e.target.disabled = true;
	e.target.innerText = __( 'Working…', 'n12s' );
	getZips();
} );

/**
 * Ajax call to run the import.  This can take a while.
 *
 * @todo: Rewrite this to run via apiRequest and the REST API.
 */
async function getZips() {
	try {
		const params = new URLSearchParams();
		params.append( 'action', 'n12s-get-zips' );
		const response = await fetch( `${ ajaxurl }?${ params }`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
		} );

		if ( ! response.ok ) {
			throw new Error(
				sprintf(
					__( 'Response status: %s', 'n12s' ),
					response.status
				)
			);
		}

		const json = await response.json();

		btnGetZips.innerText = json.data.message;

		const result = document.createElement( 'p' );
		result.innerText = __( 'Your did it!', 'n12s' );
		btnGetZips.parentElement.appendChild( result );
	} catch ( error ) {}
}

