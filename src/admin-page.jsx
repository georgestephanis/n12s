/* global ajaxurl */

import { __, sprintf } from '@wordpress/i18n';
import apiRequest from '@wordpress/api-request';

const btnGetZips = document.querySelector( '#btnGetZips' );

if ( btnGetZips ) {
	btnGetZips.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		e.target.disabled = true;
		e.target.innerText = __( 'Working…', 'n12s' );
		getZips();
	} );
}

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


const btnGetIrsAgis = document.querySelector( '#btnGetIrsAgis' );
const selectGetIrsAgis = document.querySelector( '#selectGetIrsAgis' );

btnGetIrsAgis.addEventListener( 'click', ( e ) => {
	e.preventDefault();

	const year = selectGetIrsAgis.value;

	if ( year ) {
		if ( selectGetIrsAgis.options[ selectGetIrsAgis.selectedIndex ].disabled ) {
			return;
		}

		selectGetIrsAgis.options[ selectGetIrsAgis.selectedIndex ].disabled = true;
		selectGetIrsAgis.disabled = true;
		e.target.disabled = true;
		e.target.innerText = __( 'Working…', 'n12s' );
		getIrsAgis( year );
	}

} );

/**
 * Ajax call to run the import.  This can take a while.
 *
 * @todo: Rewrite this to run via apiRequest and the REST API.
 */
async function getIrsAgis( year ) {
	try {
		const params = new URLSearchParams();
		params.append( 'action', 'n12s-get-irs-agis' );
		params.append( 'agi_year', year );
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

		btnGetIrsAgis.disabled = false;
		selectGetIrsAgis.disabled = false;

		selectGetIrsAgis.querySelector( 'option[value=' + year + ']').innerText = year + ' (' + json.data.qty + ')';

		const result = document.createElement( 'p' );
		result.innerText = json.data.message;
		btnGetIrsAgis.parentElement.appendChild( result );
	} catch ( error ) {}
}
