<?php
/**
 * Renders review questions on the customer forms (RR-11).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One renderer for the product form, the standalone form and the email
 * landing page. Element ids are `{prefix}f{field}` (text) and
 * `{prefix}f{field}-o{n}` (options), so several forms on one page stay unique.
 */
final class FieldRenderer {

	/**
	 * Markup for the questions plus the `ndvr_answers_present` marker.
	 *
	 * @param array<int,array<string,mixed>> $fields    Active questions (id => field).
	 * @param string                         $id_prefix Element id prefix.
	 * @param array<int,string>              $values    Current answers (stored form).
	 * @return string '' when there are no questions.
	 */
	public static function render( array $fields, $id_prefix, array $values = array() ) {
		if ( ! $fields ) {
			return '';
		}
		$prefix = sanitize_html_class( (string) $id_prefix );
		ob_start();
		echo '<div class="ndvr-questions">';
		foreach ( $fields as $id => $field ) {
			$id       = (int) $id;
			$name     = 'ndvr_answers[' . $id . ']';
			$current  = isset( $values[ $id ] ) ? (string) $values[ $id ] : '';
			$required = ! empty( $field['required'] );
			$mark     = $required ? ' <span class="required" aria-hidden="true">*</span>' : '';
			if ( 'text' === $field['type'] ) {
				$el = $prefix . 'f' . $id;
				printf(
					'<p class="ndvr-field ndvr-question ndvr-question-text"><label for="%1$s">%2$s%3$s</label><input type="text" id="%1$s" name="%4$s" maxlength="120" value="%5$s"%6$s /></p>',
					esc_attr( $el ),
					esc_html( $field['label'] ),
					$mark, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
					esc_attr( $name ),
					esc_attr( $current ),
					$required ? ' required' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
				);
				continue;
			}
			$options = 'yesno' === $field['type']
				? array(
					'yes' => __( 'Yes', 'rosette-reviews' ),
					'no'  => __( 'No', 'rosette-reviews' ),
				)
				: array_combine( (array) $field['options'], (array) $field['options'] );
			printf( '<fieldset class="ndvr-field ndvr-question ndvr-question-%1$s"><legend>%2$s%3$s</legend>', esc_attr( $field['type'] ), esc_html( $field['label'] ), $mark ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mark is fixed markup.
			$n = 0;
			foreach ( (array) $options as $value => $text ) {
				++$n;
				$el = $prefix . 'f' . $id . '-o' . $n;
				printf(
					'<label for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s /> %6$s</label>',
					esc_attr( $el ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					checked( (string) $value, $current, false ),
					$required ? ' required' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
					esc_html( (string) $text )
				);
			}
			echo '</fieldset>';
		}
		echo '<input type="hidden" name="ndvr_answers_present" value="1" />';
		echo '</div>';

		return (string) ob_get_clean();
	}
}
