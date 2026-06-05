<?php
/**
 * One-shot patch applied in CI before running integration tests.
 *
 * @wordpress/env bundles WordPress test utilities that call
 * PHPUnit\Util\Test::parseTestMethodAnnotations(), removed in PHPUnit 10.
 * This script replaces that entire assignment statement with a safe no-op so
 * WP_UnitTestCase-based tests run without errors.
 *
 * The method was only used to read @backupGlobals/@ticket docblock annotations,
 * none of which our test classes use, so an empty-array stub is safe.
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

// Replace the entire assignment statement:
//   $var = [\Namespace\]Test::parseTestMethodAnnotations( nested( args ), ... );
//
// [^)]+  fails on nested parens (e.g. get_class($this)) so we match the
// full assignment up to the statement-closing semicolon instead, using a
// non-greedy .*? with the /s flag so multi-line calls are also covered.
$patched = preg_replace(
	'/(\$\w+)\s*=\s*(?:\\\\?(?:\w+\\\\)*)?Test::parseTestMethodAnnotations\s*\(.*?\)\s*;/s',
	"$1 = [ 'class' => [], 'method' => [] ];",
	$content
);

if ( null === $patched || $patched === $content ) {
	echo "phpunit10-compat: pattern not matched — skipping.\n";
	exit( 0 );
}

file_put_contents( $abstract, $patched );
echo "phpunit10-compat: patched $abstract for PHPUnit 10 compatibility.\n";
