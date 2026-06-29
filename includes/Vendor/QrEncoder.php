<?php
/**
 * Minimal, dependency-free QR Code encoder (byte mode, EC level M, versions
 * 1-10, fixed mask 0). Pure PHP, no network, no extensions — WordPress.org safe.
 *
 * Original work © 2024 Nowdigiverse. Licensed under GPL-2.0-or-later.
 * See https://www.gnu.org/licenses/gpl-2.0.html
 *
 * The fixed-mask-0 approach keeps the encoder small and correct: the chosen mask
 * is written into the format information, so any conformant reader decodes it.
 *
 * @package NdvReviews
 * @license GPL-2.0-or-later
 */

namespace NdvReviews\Vendor;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a short string (e.g. a URL) into a QR matrix.
 */
class QrEncoder {

	/**
	 * GF(256) exponent table.
	 *
	 * @var int[]
	 */
	private static $exp = array();

	/**
	 * GF(256) log table.
	 *
	 * @var int[]
	 */
	private static $log = array();

	/**
	 * Byte-mode data capacity (codewords) and EC codewords per block for EC level M.
	 * [version => [total_data_codewords, ec_per_block, num_blocks_group1, data_per_block_g1, num_blocks_group2, data_per_block_g2]]
	 *
	 * @var array<int,int[]>
	 */
	private static $cap = array(
		1  => array( 16, 10, 1, 16, 0, 0 ),
		2  => array( 28, 16, 1, 28, 0, 0 ),
		3  => array( 44, 26, 1, 44, 0, 0 ),
		4  => array( 64, 18, 2, 32, 0, 0 ),
		5  => array( 86, 24, 2, 43, 0, 0 ),
		6  => array( 108, 16, 4, 27, 0, 0 ),
		7  => array( 124, 18, 4, 31, 0, 0 ),
		8  => array( 154, 22, 2, 38, 2, 39 ),
		9  => array( 182, 22, 3, 36, 2, 37 ),
		10 => array( 216, 26, 4, 43, 1, 44 ),
	);

	/**
	 * Alignment-pattern centre coordinates per version.
	 *
	 * @var array<int,int[]>
	 */
	private static $align = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * Encode a string into a boolean matrix (true = dark module).
	 *
	 * @param string $text Data (kept short, e.g. a URL).
	 * @return array<int,array<int,bool>>|null Matrix, or null if too long.
	 */
	public static function matrix( $text ) {
		self::init_gf();

		$bytes  = array_values( unpack( 'C*', $text ) );
		$length = count( $bytes );

		$version = 0;
		foreach ( self::$cap as $v => $c ) {
			// 4 bits mode + 8/16 bits length + data + 4 terminator → bytes.
			$len_bits  = $v >= 10 ? 16 : 8;
			$available = $c[0] * 8 - 4 - $len_bits;
			if ( $length * 8 <= $available ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			return null;
		}

		list( $total_data, $ec_per_block, $g1_blocks, $g1_data, $g2_blocks, $g2_data ) = self::$cap[ $version ];

		// Build the bit stream.
		$bits     = array();
		$len_bits = $version >= 10 ? 16 : 8;
		self::push_bits( $bits, 0b0100, 4 );      // byte mode.
		self::push_bits( $bits, $length, $len_bits );
		foreach ( $bytes as $b ) {
			self::push_bits( $bits, $b, 8 );
		}
		// Terminator + pad to byte boundary.
		$remaining = $total_data * 8 - count( $bits );
		self::push_bits( $bits, 0, min( 4, $remaining ) );
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		// Pad bytes.
		$pad = array( 0xEC, 0x11 );
		$i   = 0;
		while ( count( $bits ) < $total_data * 8 ) {
			self::push_bits( $bits, $pad[ $i % 2 ], 8 );
			++$i;
		}

		// Split into data codewords.
		$data = array();
		for ( $j = 0; $j < count( $bits ); $j += 8 ) {
			$byte = 0;
			for ( $k = 0; $k < 8; $k++ ) {
				$byte = ( $byte << 1 ) | $bits[ $j + $k ];
			}
			$data[] = $byte;
		}

		// Group into blocks, compute EC per block.
		$blocks = array();
		$pos    = 0;
		for ( $b = 0; $b < $g1_blocks; $b++ ) {
			$blocks[] = array_slice( $data, $pos, $g1_data );
			$pos     += $g1_data;
		}
		for ( $b = 0; $b < $g2_blocks; $b++ ) {
			$blocks[] = array_slice( $data, $pos, $g2_data );
			$pos     += $g2_data;
		}
		$ec_blocks = array();
		foreach ( $blocks as $blk ) {
			$ec_blocks[] = self::rs_ec( $blk, $ec_per_block );
		}

		// Interleave data then EC codewords.
		$final   = array();
		$max_dat = max( array_map( 'count', $blocks ) );
		for ( $c = 0; $c < $max_dat; $c++ ) {
			foreach ( $blocks as $blk ) {
				if ( isset( $blk[ $c ] ) ) {
					$final[] = $blk[ $c ];
				}
			}
		}
		for ( $c = 0; $c < $ec_per_block; $c++ ) {
			foreach ( $ec_blocks as $blk ) {
				$final[] = $blk[ $c ];
			}
		}

		return self::build_matrix( $version, $final );
	}

	/**
	 * Build the module matrix with patterns, data placement, mask 0, format info.
	 *
	 * @param int   $version Version 1-10.
	 * @param int[] $codewords Interleaved data+EC codewords.
	 * @return array<int,array<int,bool>>
	 */
	private static function build_matrix( $version, array $codewords ) {
		$size     = 17 + $version * 4;
		$m        = array();
		$reserved = array();
		for ( $r = 0; $r < $size; $r++ ) {
			$m[ $r ]        = array_fill( 0, $size, false );
			$reserved[ $r ] = array_fill( 0, $size, false );
		}

		$set = static function ( $r, $c, $v ) use ( &$m, &$reserved ) {
			$m[ $r ][ $c ]        = (bool) $v;
			$reserved[ $r ][ $c ] = true;
		};

		// Finder patterns + separators.
		foreach ( array( array( 0, 0 ), array( 0, $size - 7 ), array( $size - 7, 0 ) ) as $f ) {
			for ( $r = -1; $r <= 7; $r++ ) {
				for ( $c = -1; $c <= 7; $c++ ) {
					$rr = $f[0] + $r;
					$cc = $f[1] + $c;
					if ( $rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size ) {
						continue;
					}
					$dark = ( $r >= 0 && $r <= 6 && ( 0 === $c || 6 === $c ) ) ||
						( $c >= 0 && $c <= 6 && ( 0 === $r || 6 === $r ) ) ||
						( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
					$set( $rr, $cc, $dark );
				}
			}
		}

		// Timing patterns.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$set( 6, $i, 0 === $i % 2 );
			$set( $i, 6, 0 === $i % 2 );
		}

		// Alignment patterns.
		$centres = self::$align[ $version ];
		foreach ( $centres as $ar ) {
			foreach ( $centres as $ac ) {
				if ( ( 6 === $ar && 6 === $ac ) || ( 6 === $ar && $ac === $size - 7 ) || ( $ar === $size - 7 && 6 === $ac ) ) {
					continue;
				}
				for ( $r = -2; $r <= 2; $r++ ) {
					for ( $c = -2; $c <= 2; $c++ ) {
						$dark = ( -2 === $r || 2 === $r || -2 === $c || 2 === $c || ( 0 === $r && 0 === $c ) );
						$set( $ar + $r, $ac + $c, $dark );
					}
				}
			}
		}

		// Dark module + reserve format areas.
		$set( $size - 8, 8, true );
		for ( $i = 0; $i <= 8; $i++ ) {
			if ( ! $reserved[8][ $i ] ) {
				$reserved[8][ $i ] = true;
			}
			if ( ! $reserved[ $i ][8] ) {
				$reserved[ $i ][8] = true;
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[8][ $size - 1 - $i ] = true;
			$reserved[ $size - 1 - $i ][8] = true;
		}

		// Place data with the standard zig-zag, applying mask 0 ((r+c)%2==0).
		$bitstring = '';
		foreach ( $codewords as $cw ) {
			$bitstring .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
		}
		$bi  = 0;
		$len = strlen( $bitstring );
		$col = $size - 1;
		while ( $col > 0 ) {
			if ( 6 === $col ) {
				--$col;
			}
			for ( $i = 0; $i < $size; $i++ ) {
				$upward = ( ( ( $size - 1 - $col ) >> 1 ) % 2 ) === 0;
				$row    = $upward ? $size - 1 - $i : $i;
				foreach ( array( $col, $col - 1 ) as $cc ) {
					if ( $reserved[ $row ][ $cc ] ) {
						continue;
					}
					$bit = $bi < $len ? ( '1' === $bitstring[ $bi ] ) : false;
					++$bi;
					if ( 0 === ( ( $row + $cc ) % 2 ) ) {
						$bit = ! $bit;
					}
					$m[ $row ][ $cc ]        = $bit;
					$reserved[ $row ][ $cc ] = true;
				}
			}
			$col -= 2;
		}

		// Format information for EC level M (10) + mask 0.
		$format = self::format_bits();
		// Around top-left finder.
		$coords_a = array( array( 8, 0 ), array( 8, 1 ), array( 8, 2 ), array( 8, 3 ), array( 8, 4 ), array( 8, 5 ), array( 8, 7 ), array( 8, 8 ), array( 7, 8 ), array( 5, 8 ), array( 4, 8 ), array( 3, 8 ), array( 2, 8 ), array( 1, 8 ), array( 0, 8 ) );
		foreach ( $coords_a as $idx => $rc ) {
			$m[ $rc[0] ][ $rc[1] ] = ( '1' === $format[ $idx ] );
		}
		// Split copy along right + bottom.
		for ( $i = 0; $i < 8; $i++ ) {
			$m[ $size - 1 - $i ][8] = ( '1' === $format[ $i ] );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$m[8][ $size - 15 + $i ] = ( '1' === $format[ $i ] );
		}

		return $m;
	}

	/**
	 * 15-bit format string for EC level M + mask 0 (precomputed BCH).
	 *
	 * @return string
	 */
	private static function format_bits() {
		// EC M = 00, mask 0 = 000 → data 00000; standard masked result:
		return '101010000010010';
	}

	/**
	 * Reed-Solomon error-correction codewords.
	 *
	 * @param int[] $data Data codewords.
	 * @param int   $ec   EC codeword count.
	 * @return int[]
	 */
	private static function rs_ec( array $data, $ec ) {
		$gen = self::rs_generator( $ec );
		$res = array_merge( $data, array_fill( 0, $ec, 0 ) );

		for ( $i = 0; $i < count( $data ); $i++ ) {
			$coef = $res[ $i ];
			if ( 0 === $coef ) {
				continue;
			}
			$lc = self::$log[ $coef ];
			for ( $j = 0; $j < count( $gen ); $j++ ) {
				$res[ $i + $j ] ^= self::$exp[ ( self::$log[ $gen[ $j ] ] + $lc ) % 255 ];
			}
		}

		return array_slice( $res, count( $data ) );
	}

	/**
	 * RS generator polynomial of degree $ec.
	 *
	 * @param int $ec Degree.
	 * @return int[]
	 */
	private static function rs_generator( $ec ) {
		$g = array( 1 );
		for ( $i = 0; $i < $ec; $i++ ) {
			$next = array_fill( 0, count( $g ) + 1, 0 );
			for ( $j = 0; $j < count( $g ); $j++ ) {
				$next[ $j ]     ^= $g[ $j ];
				$next[ $j + 1 ] ^= self::$exp[ ( self::$log[ $g[ $j ] ] + $i ) % 255 ];
			}
			$g = $next;
		}

		return $g;
	}

	/**
	 * Initialise the GF(256) log/exp tables.
	 *
	 * @return void
	 */
	private static function init_gf() {
		if ( ! empty( self::$exp ) ) {
			return;
		}
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$exp[ $i ]   = $x;
			self::$log[ $x ]   = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		self::$exp[255] = self::$exp[0];
	}

	/**
	 * Append a value's bits (MSB first) to a bit array.
	 *
	 * @param int[] $bits  Bit array (by reference).
	 * @param int   $value Value.
	 * @param int   $count Number of bits.
	 * @return void
	 */
	private static function push_bits( array &$bits, $value, $count ) {
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}
}
