/**
 * Detects whether an apiFetch rejection represents credit exhaustion.
 *
 * The Worker returns HTTP 429 on exhaustion; `ProxyClient::chat()` converts
 * this into `WP_Error( 'rate_limit_exceeded', ... )` PHP-side. Either signal
 * — `error.status` (HTTP code) or `error.code` (REST error code) — surviving
 * to this point in the REST envelope identifies the out-of-credits case, so
 * both are checked rather than relying on a single transport detail that
 * may vary between REST handlers.
 *
 * @param {{status?: number, code?: string}|Error|null|undefined} error apiFetch rejection value.
 * @return {boolean} True when the error represents credit exhaustion.
 */
export function isOutOfCreditsError( error ) {
	if ( ! error ) {
		return false;
	}
	return error.status === 429 || error.code === 'rate_limit_exceeded';
}
