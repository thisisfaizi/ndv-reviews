// Polylang API stub for the RR-08 harness (appended to the child's auto_prepend file).
$GLOBALS['ndvr_qa_pll'] = array(
	'current'    => 'de',
	'post'       => array(),
	'tr'         => array(),
	'calls'      => array(),
	'registered' => array(),
);
function pll_get_post_language( $id, $field = 'slug' ) {
	$l = isset( $GLOBALS['ndvr_qa_pll']['post'][ (int) $id ] ) ? $GLOBALS['ndvr_qa_pll']['post'][ (int) $id ] : '';
	if ( '' === $l ) {
		return false;
	}
	return 'locale' === $field ? ( 'fr' === $l ? 'fr_FR' : 'en_US' ) : $l;
}
function pll_translate_string( $s, $lang ) {
	$GLOBALS['ndvr_qa_pll']['calls'][] = array( $s, $lang );
	return isset( $GLOBALS['ndvr_qa_pll']['tr'][ $lang ][ $s ] ) ? $GLOBALS['ndvr_qa_pll']['tr'][ $lang ][ $s ] : $s;
}
function pll_default_language( $field = 'slug' ) {
	return 'locale' === $field ? 'en_US' : 'en';
}
function pll_current_language( $field = 'slug' ) {
	return $GLOBALS['ndvr_qa_pll']['current'];
}
function pll_home_url( $lang = '' ) {
	return home_url( '/' . $lang . '/' );
}
function pll_register_string( $name, $string, $group = 'polylang', $multiline = false ) {
	$GLOBALS['ndvr_qa_pll']['registered'][] = array( $name, $string, $group, $multiline );
}
