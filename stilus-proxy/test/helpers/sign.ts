import { createHmac } from 'crypto';

export function signBody( body: string, secret: string ): string {
	return createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}
