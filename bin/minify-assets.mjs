/**
 * Minify the plugin's CSS/JS into *.min.css / *.min.js alongside the source.
 *
 * The source files stay human-readable (dev + WordPress.org); the *.min files
 * are what production serves (via the Support\Assets loader-src filter, which
 * swaps to .min when it exists and SCRIPT_DEBUG is off).
 *
 * Usage: node bin/minify-assets.mjs   (or: npm run build:assets)
 * Requires esbuild (devDependency).
 */
import { build } from 'esbuild';
import { readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const dirs = [ join( root, 'assets', 'css' ), join( root, 'assets', 'js' ) ];

function sources( dir ) {
	let out = [];
	let entries;
	try {
		entries = readdirSync( dir );
	} catch {
		return out; // dir may not exist in a given plugin
	}
	for ( const name of entries ) {
		const full = join( dir, name );
		if ( ! statSync( full ).isFile() ) continue;
		if ( name.includes( '.min.' ) ) continue; // don't minify a minified file
		if ( ! /\.(css|js)$/.test( name ) ) continue;
		out.push( full );
	}
	return out;
}

const files = dirs.flatMap( sources );
if ( files.length === 0 ) {
	console.log( 'No source assets found.' );
	process.exit( 0 );
}

let total = 0;
for ( const file of files ) {
	const outfile = file.replace( /\.(css|js)$/, '.min.$1' );
	await build( {
		entryPoints: [ file ],
		outfile,
		minify: true,
		legalComments: 'none',
		logLevel: 'warning',
		// CSS and JS are both handled by esbuild's loader inferred from extension.
	} );
	const before = statSync( file ).size;
	const after = statSync( outfile ).size;
	total += before - after;
	const pct = Math.round( ( 1 - after / before ) * 100 );
	console.log(
		`${ file.replace( root, '.' ) }  ${ before }B -> ${ after }B  (-${ pct }%)`
	);
}
console.log( `Done. Saved ${ total } bytes across ${ files.length } files.` );
