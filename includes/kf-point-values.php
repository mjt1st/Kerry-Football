<?php
/**
 * Kerry Football — point values.
 *
 * A week's point values are stored as text ("13,12,11,…,1") and read back in several places. One
 * league's stored default arrived as "13,12,11,10,9,8,7,6,5,4.3.2.1" — three commas typed as full
 * stops — and every reader split on commas alone, so the list ended at 4: point values 3, 2 and 1
 * vanished from the picks page, and Week Setup reported the total as short by six. Nothing rejected
 * the typo when it was saved, and League Settings had no field for correcting it afterwards.
 *
 * So: any run of non-digits separates two values, and whatever is saved — or filled into a form
 * from stored data — is put back into canonical form, which corrects the stored list in place.
 *
 * @package Kerry_Football
 * @since   1.8.25
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The point values in a stored or submitted string, in the order they were given.
 *
 * Commas, full stops, semicolons, spaces and line breaks all separate two values. Point values are
 * whole numbers above zero; anything else in the string is dropped.
 *
 * @param string|null $raw
 * @return int[]
 */
function kf_parse_point_values( $raw ) {
    $parts  = preg_split( '/\D+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
    $values = [];
    foreach ( (array) $parts as $part ) {
        $value = (int) $part;
        if ( $value > 0 ) {
            $values[] = $value;
        }
    }
    return $values;
}

/**
 * The canonical stored form of a list of point values: "13,12,11".
 *
 * Used on every save and whenever a form is filled from stored data, so a typo is corrected once
 * and cannot be copied onto the next week.
 *
 * @param string|null $raw
 * @return string
 */
function kf_normalize_point_values( $raw ) {
    return implode( ',', kf_parse_point_values( $raw ) );
}

// No closing PHP tag.
