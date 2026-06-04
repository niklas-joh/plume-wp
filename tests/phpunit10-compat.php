<?php
/**
 * One-shot patch applied in CI before running integration tests.
 *
 * @wordpress/env bundles WordPress test utilities that call
 * PHPUnit\Util\Test::parseTestMethodAnnotations(), removed in PHPUnit 10.
 * This script replaces that call with a safe no-op so our WP_UnitTestCase-
 * based tests run without errors.  The patch is idempotent and safe: the
 * method was only used to read @backupGlobals / @ticket docblock annotations,
 * none of which our test classes use.
 */

$abstract = '/wordpress-phpunit/includes/abstract-testcase.php';

if ( ! file_exists( $abstract ) ) {
	echo "phpunit10-compat: $abstract not found — skipping.\n";
	exit( 0 );
}

$content = file_get_contents( $abstract );

if ( ! str_contains( $content, 'parseTestMethodAnnotations' ) ) {
	echo "phpunit10-compat: already patched or not present — skipping.\n";
	exit( 0 );
}

// Replace every occurrence of Test::parseTestMethodAnnotations(...) with an
// empty-result array that matches the expected ['class'=>…, 'method'=>…] shape.
// The 's' flag makes '.' match newlines so multi-line calls are also caught.
$patched = preg_replace(
	'/\bTest::parseTestMethodAnnotations\s*\([^)]+\)/s',
	"[ 'class' => [], 'method' => [] ]",
	$content
);

if ( null === $patched || $patched === $content ) {
	echo "phpunit10-compat: pattern not matched — skipping.\n";
	exit( 0 );
}

file_put_contents( $abstract, $patched );
echo "phpunit10-compat: patched $abstract for PHPUnit 10 compatibility.\n";
