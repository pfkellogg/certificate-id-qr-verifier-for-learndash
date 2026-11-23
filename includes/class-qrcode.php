<?php
/**
 * CP_LD_QRCode — Pure PHP QR Code generator. Outputs inline SVG.
 *
 * Supports: byte-mode encoding, error-correction level M, versions 1–10.
 * No external libraries or GD required.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_QRCode {

	// -----------------------------------------------------------------------
	// GF(256) arithmetic (primitive polynomial x^8+x^4+x^3+x^2+1 = 285)
	// -----------------------------------------------------------------------

	private static $EXP = array();
	private static $LOG = array();
	private static $gf_ready = false;

	private static function gf_init() {
		if ( self::$gf_ready ) {
			return;
		}
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$EXP[ $i ] = $x;
			self::$LOG[ $x ] = $i;
			$x = ( $x << 1 ) ^ ( $x & 128 ? 285 : 0 );
		}
		for ( $i = 255; $i < 512; $i++ ) {
			self::$EXP[ $i ] = self::$EXP[ $i - 255 ];
		}
		self::$gf_ready = true;
	}

	private static function gf_mul( $a, $b ) {
		if ( $a === 0 || $b === 0 ) {
			return 0;
		}
		return self::$EXP[ ( self::$LOG[ $a ] + self::$LOG[ $b ] ) % 255 ];
	}

	private static function poly_mul( $p, $q ) {
		$r = array_fill( 0, count( $p ) + count( $q ) - 1, 0 );
		foreach ( $p as $i => $pi ) {
			foreach ( $q as $j => $qj ) {
				$r[ $i + $j ] ^= self::gf_mul( $pi, $qj );
			}
		}
		return $r;
	}

	/** Generate RS generator polynomial for $n EC codewords. */
	private static function rs_generator( $n ) {
		$g = array( 1 );
		for ( $i = 0; $i < $n; $i++ ) {
			$g = self::poly_mul( $g, array( 1, self::$EXP[ $i ] ) );
		}
		return $g;
	}

	/** Reed-Solomon encode: return $n_ec error-correction codewords. */
	private static function rs_encode( $data, $n_ec ) {
		$gen = self::rs_generator( $n_ec );
		$msg = array_merge( $data, array_fill( 0, $n_ec, 0 ) );
		$len = count( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $msg[ $i ];
			if ( $c !== 0 ) {
				for ( $j = 1; $j <= $n_ec; $j++ ) {
					$msg[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $c );
				}
			}
		}
		return array_slice( $msg, $len );
	}

	// -----------------------------------------------------------------------
	// Version / block data for EC level M
	//
	// Each entry: [ max_data_bytes, ec_per_block, blocks1, data_per_block1,
	//                                             blocks2, data_per_block2 ]
	// -----------------------------------------------------------------------

	private static $VER = array(
		1  => array( 16,  10, 1, 16, 0,  0 ),
		2  => array( 28,  16, 1, 28, 0,  0 ),
		3  => array( 44,  13, 2, 22, 0,  0 ),
		4  => array( 64,  18, 2, 32, 0,  0 ),
		5  => array( 86,  24, 2, 43, 0,  0 ),
		6  => array( 108, 16, 4, 27, 0,  0 ),
		7  => array( 124, 18, 4, 31, 0,  0 ),
		8  => array( 154, 22, 2, 38, 2, 39 ),
		9  => array( 182, 22, 3, 36, 2, 37 ),
		10 => array( 216, 26, 4, 43, 1, 44 ),
	);

	// Alignment pattern row/col positions per version (version => centre coords).
	private static $ALIGN = array(
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

	// Format info bit strings for EC level M (2 bits = 01) XOR masked and BCH-encoded.
	// Index = mask pattern 0-7.
	private static $FORMAT = array(
		0 => 0x5412,
		1 => 0x5125,
		2 => 0x5E7C,
		3 => 0x5B4B,
		4 => 0x45F9,
		5 => 0x40CE,
		6 => 0x4F97,
		7 => 0x4AA0,
	);

	// -----------------------------------------------------------------------
	// Public entry point
	// -----------------------------------------------------------------------

	/**
	 * Generate an inline SVG QR code.
	 *
	 * @param string $text   Data to encode (typically a URL).
	 * @param int    $size   Rendered pixel size (width and height).
	 * @return string  SVG markup, or empty string on failure.
	 */
	public static function svg( $text, $size = 200 ) {
		self::gf_init();

		$bytes   = array_values( unpack( 'C*', $text ) );
		$version = self::pick_version( count( $bytes ) );
		if ( ! $version ) {
			return ''; // Data too long for version 10.
		}

		$codewords = self::encode( $bytes, $version );
		$matrix    = self::build_matrix( $codewords, $version );
		$matrix    = self::apply_best_mask( $matrix, $version );

		return self::render_svg( $matrix, $size );
	}

	/**
	 * Generate a QR code as raw PNG bytes.
	 * Pass to TCPDF Image() as '@' . CP_LD_QRCode::png_bytes(...).
	 * Returns empty string if GD is unavailable.
	 *
	 * @param string $text
	 * @param int    $size  Pixel dimensions of the output image.
	 * @return string  Raw PNG bytes, or '' on failure.
	 */
	public static function png_bytes( $text, $size = 200 ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}

		self::gf_init();

		$bytes   = array_values( unpack( 'C*', $text ) );
		$version = self::pick_version( count( $bytes ) );
		if ( ! $version ) {
			return '';
		}

		$codewords = self::encode( $bytes, $version );
		$matrix    = self::build_matrix( $codewords, $version );
		$matrix    = self::apply_best_mask( $matrix, $version );

		$n         = count( $matrix );
		$quiet     = 4;
		$modules   = $n + $quiet * 2;
		$module_px = max( 1, (int) round( $size / $modules ) );
		$canvas    = $modules * $module_px;

		$img   = imagecreatetruecolor( $canvas, $canvas );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$black = imagecolorallocate( $img, 0, 0, 0 );
		imagefill( $img, 0, 0, $white );

		for ( $row = 0; $row < $n; $row++ ) {
			for ( $col = 0; $col < $n; $col++ ) {
				if ( $matrix[ $row ][ $col ] === 1 ) {
					$x = ( $col + $quiet ) * $module_px;
					$y = ( $row + $quiet ) * $module_px;
					imagefilledrectangle( $img, $x, $y, $x + $module_px - 1, $y + $module_px - 1, $black );
				}
			}
		}

		ob_start();
		imagepng( $img );
		$png = ob_get_clean();
		imagedestroy( $img );

		return (string) $png;
	}

	/**
	 * Generate a QR code as a base64 PNG data URI suitable for use in an
	 * <img src="data:image/png;base64,..."> tag inside TCPDF's WriteHTML.
	 * Returns empty string if GD is unavailable.
	 *
	 * @param string $text
	 * @param int    $size  Pixel dimensions of the output image.
	 * @return string  data URI, or '' on failure.
	 */
	public static function png_data_uri( $text, $size = 200 ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}

		self::gf_init();

		$bytes   = array_values( unpack( 'C*', $text ) );
		$version = self::pick_version( count( $bytes ) );
		if ( ! $version ) {
			return '';
		}

		$codewords = self::encode( $bytes, $version );
		$matrix    = self::build_matrix( $codewords, $version );
		$matrix    = self::apply_best_mask( $matrix, $version );

		return self::render_png_data_uri( $matrix, $size );
	}

	/**
	 * Render the QR matrix to a PNG and return it as a base64 data URI.
	 *
	 * @param array $matrix
	 * @param int   $size_px
	 * @return string  data URI, or '' on failure.
	 */
	private static function render_png_data_uri( $matrix, $size_px ) {
		$n         = count( $matrix );
		$quiet     = 4;
		$modules   = $n + $quiet * 2;
		$module_px = max( 1, (int) round( $size_px / $modules ) );
		$canvas    = $modules * $module_px;

		$img   = imagecreatetruecolor( $canvas, $canvas );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$black = imagecolorallocate( $img, 0, 0, 0 );
		imagefill( $img, 0, 0, $white );

		for ( $row = 0; $row < $n; $row++ ) {
			for ( $col = 0; $col < $n; $col++ ) {
				if ( $matrix[ $row ][ $col ] === 1 ) {
					$x = ( $col + $quiet ) * $module_px;
					$y = ( $row + $quiet ) * $module_px;
					imagefilledrectangle( $img, $x, $y, $x + $module_px - 1, $y + $module_px - 1, $black );
				}
			}
		}

		ob_start();
		imagepng( $img );
		$png = ob_get_clean();
		imagedestroy( $img );

		if ( ! $png ) {
			return '';
		}

		return 'data:image/png;base64,' . base64_encode( $png );
	}

	// -----------------------------------------------------------------------
	// Version selection
	// -----------------------------------------------------------------------

	private static function pick_version( $byte_count ) {
		foreach ( self::$VER as $v => $info ) {
			if ( $byte_count <= $info[0] ) {
				return $v;
			}
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// Data encoding
	// -----------------------------------------------------------------------

	/**
	 * Produce the full interleaved codeword sequence (data + EC).
	 *
	 * @param int[] $bytes    Raw data bytes.
	 * @param int   $version
	 * @return int[]  Codewords ready for matrix placement.
	 */
	private static function encode( $bytes, $version ) {
		list( $max, $ec_n, $b1, $d1, $b2, $d2 ) = self::$VER[ $version ];

		// --- bit stream ---
		$bits  = array();
		// Mode indicator: byte mode = 0100.
		self::push_bits( $bits, 0b0100, 4 );
		// Character count (8 bits for version 1-9).
		self::push_bits( $bits, count( $bytes ), 8 );
		// Data bytes.
		foreach ( $bytes as $b ) {
			self::push_bits( $bits, $b, 8 );
		}
		// Terminator.
		self::push_bits( $bits, 0, 4 );
		// Pad to byte boundary.
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		// Pad codewords (0xEC, 0x11 alternating).
		$cw   = self::bits_to_bytes( $bits );
		$pad  = array( 0xEC, 0x11 );
		$pi   = 0;
		while ( count( $cw ) < $max ) {
			$cw[] = $pad[ $pi % 2 ];
			$pi++;
		}

		// --- split into blocks and add EC ---
		$blocks      = array();
		$ec_blocks   = array();
		$offset      = 0;
		for ( $i = 0; $i < $b1; $i++ ) {
			$block     = array_slice( $cw, $offset, $d1 );
			$blocks[]  = $block;
			$ec_blocks[] = self::rs_encode( $block, $ec_n );
			$offset   += $d1;
		}
		for ( $i = 0; $i < $b2; $i++ ) {
			$block     = array_slice( $cw, $offset, $d2 );
			$blocks[]  = $block;
			$ec_blocks[] = self::rs_encode( $block, $ec_n );
			$offset   += $d2;
		}

		// --- interleave data codewords ---
		$interleaved = array();
		$max_len = $b2 > 0 ? $d2 : $d1;
		for ( $col = 0; $col < $max_len; $col++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $col ] ) ) {
					$interleaved[] = $block[ $col ];
				}
			}
		}

		// --- interleave EC codewords ---
		for ( $col = 0; $col < $ec_n; $col++ ) {
			foreach ( $ec_blocks as $ec ) {
				$interleaved[] = $ec[ $col ];
			}
		}

		return $interleaved;
	}

	private static function push_bits( &$bits, $value, $length ) {
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	private static function bits_to_bytes( $bits ) {
		$bytes = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$b = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$b = ( $b << 1 ) | ( isset( $bits[ $i + $j ] ) ? $bits[ $i + $j ] : 0 );
			}
			$bytes[] = $b;
		}
		return $bytes;
	}

	// -----------------------------------------------------------------------
	// Matrix construction
	// -----------------------------------------------------------------------

	/**
	 * Build the QR matrix WITHOUT format/mask info and WITHOUT data.
	 * Returns the function-pattern matrix and a reserved[] map.
	 */
	private static function build_matrix( $codewords, $version ) {
		$size = 4 * $version + 17;
		// 0 = light, 1 = dark, -1 = not yet placed.
		$m = array_fill( 0, $size, array_fill( 0, $size, -1 ) );
		// Reserved = function pattern (don't overwrite with data).
		$r = array_fill( 0, $size, array_fill( 0, $size, false ) );

		self::place_finder( $m, $r, 0, 0 );
		self::place_finder( $m, $r, 0, $size - 7 );
		self::place_finder( $m, $r, $size - 7, 0 );
		self::place_timing( $m, $r, $size );
		self::place_alignment( $m, $r, $version, $size );
		self::reserve_format_areas( $m, $r, $size );
		self::place_dark_module( $m, $r, $version );
		self::place_data( $m, $r, $codewords, $size );

		return $m;
	}

	private static function set( &$m, &$r, $row, $col, $val ) {
		$m[ $row ][ $col ] = $val;
		$r[ $row ][ $col ] = true;
	}

	private static function place_finder( &$m, &$r, $top, $left ) {
		for ( $row = -1; $row <= 7; $row++ ) {
			for ( $col = -1; $col <= 7; $col++ ) {
				$ro = $top + $row;
				$co = $left + $col;
				if ( $ro < 0 || $co < 0 || $ro >= count( $m ) || $co >= count( $m ) ) {
					continue;
				}
				// Separator = 0; finder pattern itself:
				if ( $row === -1 || $row === 7 || $col === -1 || $col === 7 ) {
					$val = 0;
				} elseif ( $row >= 1 && $row <= 5 && $col >= 1 && $col <= 5 ) {
					$val = ( $row >= 2 && $row <= 4 && $col >= 2 && $col <= 4 ) ? 0 : 1;
				} else {
					$val = 1;
				}
				self::set( $m, $r, $ro, $co, $val );
			}
		}
	}

	private static function place_timing( &$m, &$r, $size ) {
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$val = ( $i % 2 === 0 ) ? 1 : 0;
			self::set( $m, $r, 6, $i, $val );
			self::set( $m, $r, $i, 6, $val );
		}
	}

	private static function place_alignment( &$m, &$r, $version, $size ) {
		$pos = self::$ALIGN[ $version ];
		if ( empty( $pos ) ) {
			return;
		}
		foreach ( $pos as $row ) {
			foreach ( $pos as $col ) {
				// Skip positions that overlap finder patterns.
				if (
					( $row <= 8 && $col <= 8 ) ||
					( $row <= 8 && $col >= $size - 8 ) ||
					( $row >= $size - 8 && $col <= 8 )
				) {
					continue;
				}
				for ( $dr = -2; $dr <= 2; $dr++ ) {
					for ( $dc = -2; $dc <= 2; $dc++ ) {
						$v = ( abs( $dr ) === 2 || abs( $dc ) === 2 || ( $dr === 0 && $dc === 0 ) ) ? 1 : 0;
						self::set( $m, $r, $row + $dr, $col + $dc, $v );
					}
				}
			}
		}
	}

	private static function reserve_format_areas( &$m, &$r, $size ) {
		// Top-left horizontal.
		for ( $i = 0; $i <= 8; $i++ ) {
			$m[8][$i] = 0;
			$r[8][$i] = true;
		}
		// Top-left vertical.
		for ( $i = 0; $i <= 8; $i++ ) {
			$m[$i][8] = 0;
			$r[$i][8] = true;
		}
		// Top-right.
		for ( $i = $size - 8; $i < $size; $i++ ) {
			$m[8][$i] = 0;
			$r[8][$i] = true;
		}
		// Bottom-left.
		for ( $i = $size - 7; $i < $size; $i++ ) {
			$m[$i][8] = 0;
			$r[$i][8] = true;
		}
	}

	private static function place_dark_module( &$m, &$r, $version ) {
		$row = 4 * $version + 9;
		$m[ $row ][8] = 1;
		$r[ $row ][8] = true;
	}

	/**
	 * Place codeword bits in the matrix using the standard zigzag order.
	 */
	private static function place_data( &$m, &$r, $codewords, $size ) {
		// Expand codewords to bit stream.
		$bits = array();
		foreach ( $codewords as $cw ) {
			for ( $b = 7; $b >= 0; $b-- ) {
				$bits[] = ( $cw >> $b ) & 1;
			}
		}

		$bit_idx = 0;
		$going_up = true;

		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( $right === 6 ) {
				$right--; // Skip vertical timing column.
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				$row = $going_up ? ( $size - 1 - $vert ) : $vert;
				for ( $lr = 0; $lr < 2; $lr++ ) {
					$col = $right - $lr;
					if ( ! $r[ $row ][ $col ] ) {
						$m[ $row ][ $col ] = isset( $bits[ $bit_idx ] ) ? $bits[ $bit_idx ] : 0;
						$bit_idx++;
					}
				}
			}
			$going_up = ! $going_up;
		}
	}

	// -----------------------------------------------------------------------
	// Masking
	// -----------------------------------------------------------------------

	/**
	 * Try all 8 mask patterns, apply format info to each, pick lowest penalty.
	 */
	private static function apply_best_mask( $matrix, $version ) {
		$size        = count( $matrix );
		$best_score  = PHP_INT_MAX;
		$best_matrix = $matrix;

		for ( $pattern = 0; $pattern <= 7; $pattern++ ) {
			$m = $matrix;
			self::apply_mask( $m, $pattern, $size );
			self::place_format( $m, $pattern, $size );
			$score = self::penalty( $m, $size );
			if ( $score < $best_score ) {
				$best_score  = $score;
				$best_matrix = $m;
			}
		}

		return $best_matrix;
	}

	private static function mask_bit( $pattern, $row, $col ) {
		switch ( $pattern ) {
			case 0: return ( $row + $col ) % 2 === 0;
			case 1: return $row % 2 === 0;
			case 2: return $col % 3 === 0;
			case 3: return ( $row + $col ) % 3 === 0;
			case 4: return ( intdiv( $row, 2 ) + intdiv( $col, 3 ) ) % 2 === 0;
			case 5: return ( $row * $col ) % 2 + ( $row * $col ) % 3 === 0;
			case 6: return ( ( $row * $col ) % 2 + ( $row * $col ) % 3 ) % 2 === 0;
			case 7: return ( ( $row + $col ) % 2 + ( $row * $col ) % 3 ) % 2 === 0;
		}
		return false;
	}

	private static function apply_mask( &$m, $pattern, $size ) {
		for ( $row = 0; $row < $size; $row++ ) {
			for ( $col = 0; $col < $size; $col++ ) {
				if ( $m[ $row ][ $col ] !== -1 && self::mask_bit( $pattern, $row, $col ) ) {
					$m[ $row ][ $col ] ^= 1;
				}
			}
		}
	}

	private static function place_format( &$m, $pattern, $size ) {
		$fmt = self::$FORMAT[ $pattern ];
		// 15 format bits: positions around top-left and top-right/bottom-left finders.
		$positions = array(
			array( 8, 0 ), array( 8, 1 ), array( 8, 2 ), array( 8, 3 ),
			array( 8, 4 ), array( 8, 5 ), array( 8, 7 ), array( 8, 8 ),
			array( 7, 8 ), array( 5, 8 ), array( 4, 8 ), array( 3, 8 ),
			array( 2, 8 ), array( 1, 8 ), array( 0, 8 ),
		);
		for ( $i = 0; $i < 15; $i++ ) {
			$bit = ( $fmt >> ( 14 - $i ) ) & 1;
			$m[ $positions[ $i ][0] ][ $positions[ $i ][1] ] = $bit;
		}
		// Mirror to top-right.
		for ( $i = 0; $i < 7; $i++ ) {
			$m[8][ $size - 1 - $i ] = ( $fmt >> $i ) & 1;
		}
		// Mirror to bottom-left.
		for ( $i = 0; $i < 8; $i++ ) {
			$m[ $size - 8 + $i ][8] = ( $fmt >> ( 7 + $i ) ) & 1;
		}
	}

	// -----------------------------------------------------------------------
	// Penalty scoring
	// -----------------------------------------------------------------------

	private static function penalty( $m, $size ) {
		$score = 0;

		// Rule 1: runs of 5+ same colour in rows and columns.
		for ( $row = 0; $row < $size; $row++ ) {
			$score += self::run_penalty( $m[ $row ] );
		}
		for ( $col = 0; $col < $size; $col++ ) {
			$column = array();
			for ( $row = 0; $row < $size; $row++ ) {
				$column[] = $m[ $row ][ $col ];
			}
			$score += self::run_penalty( $column );
		}

		// Rule 2: 2×2 blocks.
		for ( $row = 0; $row < $size - 1; $row++ ) {
			for ( $col = 0; $col < $size - 1; $col++ ) {
				$v = $m[ $row ][ $col ];
				if ( $v === $m[ $row ][ $col + 1 ] &&
					 $v === $m[ $row + 1 ][ $col ] &&
					 $v === $m[ $row + 1 ][ $col + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 4: proportion of dark modules.
		$dark  = 0;
		$total = $size * $size;
		for ( $row = 0; $row < $size; $row++ ) {
			foreach ( $m[ $row ] as $v ) {
				if ( $v === 1 ) {
					$dark++;
				}
			}
		}
		$pct   = (int) ( $dark / $total * 100 );
		$prev5 = (int) ( $pct / 5 ) * 5;
		$next5 = $prev5 + 5;
		$score += min( abs( $prev5 - 50 ), abs( $next5 - 50 ) ) / 5 * 10;

		return $score;
	}

	private static function run_penalty( $line ) {
		$score = 0;
		$run   = 1;
		for ( $i = 1; $i < count( $line ); $i++ ) {
			if ( $line[ $i ] === $line[ $i - 1 ] ) {
				$run++;
			} else {
				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}
				$run = 1;
			}
		}
		if ( $run >= 5 ) {
			$score += 3 + ( $run - 5 );
		}
		return $score;
	}

	// -----------------------------------------------------------------------
	// SVG rendering
	// -----------------------------------------------------------------------

	private static function render_svg( $matrix, $size_px ) {
		$n        = count( $matrix );
		$quiet    = 4; // 4-module quiet zone.
		$modules  = $n + $quiet * 2;
		$module_px = $size_px / $modules;

		$rects = '';
		for ( $row = 0; $row < $n; $row++ ) {
			for ( $col = 0; $col < $n; $col++ ) {
				if ( $matrix[ $row ][ $col ] === 1 ) {
					$x = round( ( $col + $quiet ) * $module_px, 2 );
					$y = round( ( $row + $quiet ) * $module_px, 2 );
					$w = round( $module_px, 2 );
					$rects .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $w . '"/>';
				}
			}
		}

		$vb = '0 0 ' . $size_px . ' ' . $size_px;

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr( $vb ) . '" '
			. 'width="' . esc_attr( $size_px ) . '" height="' . esc_attr( $size_px ) . '" '
			. 'role="img" aria-label="' . esc_attr__( 'Certificate verification QR code', 'certificate-id-qr-verifier-for-learndash' ) . '">'
			. '<rect width="' . $size_px . '" height="' . $size_px . '" fill="#fff"/>'
			. '<g fill="#000">' . $rects . '</g>'
			. '</svg>';
	}
}
