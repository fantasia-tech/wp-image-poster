<?php
/*
Plugin Name: WP Image Poster
Plugin URI: https://github.com/fantasia-tech/wp-image-poster
Description: Uploading an image automatically creates a draft post with Exif data and sets the image as the featured image. Configurable via settings.
Version: 1.6
Author: Kazuki Sakane
Author URI: https://www.linkedin.com/in/kazuki-sakane/
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direct access denied
}

// -----------------------------------------------------------------------------
// YAML Translation System
// -----------------------------------------------------------------------------

/**
 * Get translated string based on current locale using YAML files.
 *
 * @param string $text English text (Key).
 * @return string Translated text.
 */
function etp_trans( $text ) {
    static $translations = null;

    // Load translations only once
    if ( $translations === null ) {
        $translations = array();
        $locale = get_locale(); // e.g., 'ja', 'ja_JP'
        
        // Try to find a matching YAML file (e.g., ja.yml or ja_JP.yml)
        // Look for 'ja.yml' if locale is 'ja_JP' to be flexible
        $lang_code = substr( $locale, 0, 2 );
        $files_to_check = array( $locale . '.yml', $lang_code . '.yml' );
        
        $file_path = '';
        foreach ( $files_to_check as $file_name ) {
            $path = plugin_dir_path( __FILE__ ) . 'languages/' . $file_name;
            if ( file_exists( $path ) ) {
                $file_path = $path;
                break;
            }
        }

        if ( $file_path ) {
            $translations = etp_parse_simple_yaml( $file_path );
        }
    }

    // Return translated text if exists, otherwise return original
    if ( isset( $translations[ $text ] ) ) {
        return $translations[ $text ];
    }

    return $text;
}

/**
 * Simple YAML parser for flat Key-Value pairs.
 * * NOTE: PHP does not have a built-in YAML parser without extensions.
 * This is a lightweight implementation for simple translation files.
 *
 * @param string $file_path Path to the YAML file.
 * @return array Parsed array.
 */
function etp_parse_simple_yaml( $file_path ) {
    $data = array();
    $lines = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

    if ( ! $lines ) return $data;

    foreach ( $lines as $line ) {
        $line = trim( $line );
        
        // Skip comments and empty lines
        if ( empty( $line ) || strpos( $line, '#' ) === 0 ) {
            continue;
        }

        // Split by first colon
        $parts = explode( ':', $line, 2 );
        
        if ( count( $parts ) === 2 ) {
            $key = trim( $parts[0] );
            $value = trim( $parts[1] );
            
            // Remove surrounding quotes if present (simple handling)
            $key = trim( $key, "'\"" );
            $value = trim( $value, "'\"" );

            $data[ $key ] = $value;
        }
    }

    return $data;
}


// -----------------------------------------------------------------------------
// Admin Settings
// -----------------------------------------------------------------------------

/**
 * Add settings page to the admin menu.
 */
function etp_add_admin_menu() {
    add_options_page(
        etp_trans( 'Exif to Post Settings' ), // Page title
        etp_trans( 'Exif to Post' ),          // Menu title
        'manage_options',
        'wp-image-poster',
        'etp_options_page'
    );
}
add_action( 'admin_menu', 'etp_add_admin_menu' );

/**
 * Register settings and fields.
 */
function etp_settings_init() {
    register_setting( 'etpPlugin', 'etp_settings' );

    add_settings_section(
        'etp_plugin_section',
        etp_trans( 'Exif Fields to Display' ),
        'etp_settings_section_callback',
        'etpPlugin'
    );

    $fields = array(
        'date_taken'      => etp_trans( 'Date Taken' ),
        'camera_model'    => etp_trans( 'Camera Model' ),
        'f_number'        => etp_trans( 'Aperture (F-Number)' ),
        'exposure_time'   => etp_trans( 'Shutter Speed' ),
        'iso'             => etp_trans( 'ISO' ),
        'focal_length'    => etp_trans( 'Focal Length' ),
        'gps_coordinates' => etp_trans( 'GPS Coordinates' ),
    );

    foreach ( $fields as $key => $label ) {
        add_settings_field(
            'etp_field_' . $key,
            $label,
            'etp_checkbox_field_render',
            'etpPlugin',
            'etp_plugin_section',
            array( 'key' => $key )
        );
    }
}
add_action( 'admin_init', 'etp_settings_init' );

/**
 * Render checkbox field.
 */
function etp_checkbox_field_render( $args ) {
    $options = get_option( 'etp_settings', etp_get_default_options() );
    $key = $args['key'];
    $checked = isset( $options[ $key ] ) ? checked( $options[ $key ], 1, false ) : '';
    ?>
    <input type='checkbox' name='etp_settings[<?php echo esc_attr( $key ); ?>]' value='1' <?php echo $checked; ?>>
    <?php
}

/**
 * Section description callback.
 */
function etp_settings_section_callback() {
    echo esc_html( etp_trans( 'Check the information you want to include in the automatically created post.' ) );
}

/**
 * Render the options page.
 */
function etp_options_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( etp_trans( 'Exif to Post Settings' ) ); ?></h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields( 'etpPlugin' );
            do_settings_sections( 'etpPlugin' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Get default options.
 */
function etp_get_default_options() {
    return array(
        'date_taken'      => 1,
        'camera_model'    => 1,
        'f_number'        => 1,
        'exposure_time'   => 1,
        'iso'             => 1,
        'focal_length'    => 1,
        'gps_coordinates' => 1,
    );
}


// -----------------------------------------------------------------------------
// Main Logic
// -----------------------------------------------------------------------------

/**
 * Create a post from Exif data when an image is uploaded.
 *
 * @param int $attachment_id The ID of the uploaded attachment.
 */
function etp_create_post_from_exif( $attachment_id ) {
    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return;
    }

    $file_path = get_attached_file( $attachment_id );
    $mime_type = get_post_mime_type( $attachment_id );

    if ( $mime_type !== 'image/jpeg' && $mime_type !== 'image/jpg' ) {
        return;
    }

    if ( ! function_exists( 'exif_read_data' ) ) {
        error_log( 'Exif to Post: exif_read_data function is not enabled on this server.' );
        return;
    }

    $exif = @exif_read_data( $file_path );

    if ( ! $exif ) {
        return;
    }

    $options = get_option( 'etp_settings', etp_get_default_options() );

    // --- Prepare Data ---

    // 1. Camera Model
    $camera_str = '';
    if ( ! empty( $options['camera_model'] ) ) {
        $camera_make  = isset( $exif['Make'] ) ? trim( $exif['Make'] ) : '';
        $camera_model = isset( $exif['Model'] ) ? trim( $exif['Model'] ) : '';
        
        if ( stripos( $camera_model, $camera_make ) === 0 ) {
            $camera_str = $camera_model;
        } else {
            $camera_str = trim( $camera_make . ' ' . $camera_model );
        }
        if ( empty( $camera_str ) ) $camera_str = 'Unknown';
    }

    // 2. Date Taken
    $date_taken = '';
    if ( ! empty( $options['date_taken'] ) ) {
        $raw_date = isset( $exif['DateTimeOriginal'] ) ? $exif['DateTimeOriginal'] : '';
        if ( ! empty( $raw_date ) ) {
            // Use date_i18n for localized date format based on WordPress settings
            $date_taken = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $raw_date ) );
        }
    }

    // 3. Aperture
    $f_number = '';
    if ( ! empty( $options['f_number'] ) && isset( $exif['FNumber'] ) ) {
        $f_val = etp_convert_fraction( $exif['FNumber'] );
        if ( $f_val ) {
            $f_number = 'f/' . round( $f_val, 1 );
        }
    }

    // 4. Shutter Speed
    $exposure_time = '';
    if ( ! empty( $options['exposure_time'] ) && isset( $exif['ExposureTime'] ) ) {
        $exposure_time = etp_format_shutter_speed( $exif['ExposureTime'] );
    }

    // 5. ISO
    $iso = '';
    if ( ! empty( $options['iso'] ) ) {
        $iso_val = isset( $exif['ISOSpeedRatings'] ) ? $exif['ISOSpeedRatings'] : ( isset( $exif['ISOSpeed'] ) ? $exif['ISOSpeed'] : '' );
        if ( is_array( $iso_val ) ) {
            $iso_val = $iso_val[0];
        }
        $iso = $iso_val;
    }

    // 6. Focal Length
    $focal_length = '';
    if ( ! empty( $options['focal_length'] ) && isset( $exif['FocalLength'] ) ) {
        $fl_val = etp_convert_fraction( $exif['FocalLength'] );
        if ( $fl_val ) {
            $focal_length = round( $fl_val, 0 ) . 'mm';
        }
    }

    // 7. GPS Coordinates
    $gps_link = '';
    $osm_embed = '';

    if ( ! empty( $options['gps_coordinates'] ) ) {
        if ( isset( $exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'] ) ) {
            $lat = etp_gps_to_decimal( $exif['GPSLatitude'], $exif['GPSLatitudeRef'] );
            $lng = etp_gps_to_decimal( $exif['GPSLongitude'], $exif['GPSLongitudeRef'] );
            
            if ( $lat && $lng ) {
                // Text Link
                $link = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=15/' . $lat . '/' . $lng;
                $gps_link = '<a href="' . esc_url( $link ) . '" target="_blank">' . round( $lat, 6 ) . ', ' . round( $lng, 6 ) . '</a>';

                // OSM Embed Code
                // Calculate Bounding Box (bbox) approx 1km box
                $offset = 0.005;
                $min_lon = $lng - $offset;
                $min_lat = $lat - $offset;
                $max_lon = $lng + $offset;
                $max_lat = $lat + $offset;

                $embed_url = "https://www.openstreetmap.org/export/embed.html?bbox={$min_lon},{$min_lat},{$max_lon},{$max_lat}&amp;layer=mapnik&amp;marker={$lat},{$lng}";
                
                $osm_embed = '<!-- wp:html -->';
                $osm_embed .= '<div style="margin-top: 20px;">';
                $osm_embed .= '<iframe width="100%" height="300" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="' . esc_url( $embed_url ) . '" style="border: 1px solid #ccc"></iframe>';
                $osm_embed .= '<br/><small><a href="' . esc_url( $link ) . '" target="_blank">' . etp_trans( 'View Larger Map' ) . '</a></small>';
                $osm_embed .= '</div>';
                $osm_embed .= '<!-- /wp:html -->';
            }
        }
    }

    // --- Create Post Content (HTML) ---
    
    $rows = '';
    if ( $date_taken )    $rows .= "<tr><td><strong>" . etp_trans( 'Date Taken' ) . "</strong></td><td>{$date_taken}</td></tr>";
    if ( $camera_str )    $rows .= "<tr><td><strong>" . etp_trans( 'Camera Model' ) . "</strong></td><td>{$camera_str}</td></tr>";
    if ( $f_number )      $rows .= "<tr><td><strong>" . etp_trans( 'Aperture (F-Number)' ) . "</strong></td><td>{$f_number}</td></tr>";
    if ( $exposure_time ) $rows .= "<tr><td><strong>" . etp_trans( 'Shutter Speed' ) . "</strong></td><td>{$exposure_time}</td></tr>";
    if ( $iso )           $rows .= "<tr><td><strong>" . etp_trans( 'ISO' ) . "</strong></td><td>{$iso}</td></tr>";
    if ( $focal_length )  $rows .= "<tr><td><strong>" . etp_trans( 'Focal Length' ) . "</strong></td><td>{$focal_length}</td></tr>";
    if ( $gps_link )      $rows .= "<tr><td><strong>" . etp_trans( 'GPS Coordinates' ) . "</strong></td><td>{$gps_link}</td></tr>";

    $content = '<!-- wp:paragraph -->';
    $content .= '<p>' . etp_trans( 'Exif data for the uploaded photo.' ) . '</p>';
    $content .= '<!-- /wp:paragraph -->';

    if ( ! empty( $rows ) ) {
        $content .= '<!-- wp:table -->';
        $content .= '<figure class="wp-block-table"><table><tbody>';
        $content .= $rows;
        $content .= '</tbody></table></figure>';
        $content .= '<!-- /wp:table -->';
    } else {
        $content .= '<!-- wp:paragraph --><p>' . etp_trans( '(No Exif data available)' ) . '</p><!-- /wp:paragraph -->';
    }

    // Append Map if available
    if ( $osm_embed ) {
        $content .= $osm_embed;
    }

    // --- Insert Post ---
    $post_title = isset( $exif['FileName'] ) ? $exif['FileName'] : etp_trans( 'Photo Upload' ) . ' ' . date_i18n( 'Y-m-d' );
    
    $new_post = array(
        'post_title'    => $post_title,
        'post_content'  => $content,
        'post_status'   => 'draft',
        'post_type'     => 'post',
        'post_author'   => get_current_user_id(),
    );

    $post_id = wp_insert_post( $new_post );

    if ( ! is_wp_error( $post_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
    }
}
add_action( 'add_attachment', 'etp_create_post_from_exif' );


/**
 * Helper: Convert fraction string to number.
 */
function etp_convert_fraction( $fraction ) {
    if ( is_numeric( $fraction ) ) {
        return $fraction;
    }
    if ( strpos( $fraction, '/' ) !== false ) {
        $parts = explode( '/', $fraction );
        if ( isset( $parts[0] ) && isset( $parts[1] ) && $parts[1] != 0 ) {
            return $parts[0] / $parts[1];
        }
    }
    return 0;
}

/**
 * Helper: Format shutter speed.
 */
function etp_format_shutter_speed( $fraction ) {
    if ( strpos( $fraction, '/' ) !== false ) {
        $parts = explode( '/', $fraction );
        $numerator = isset($parts[0]) ? (int)$parts[0] : 0;
        $denominator = isset($parts[1]) ? (int)$parts[1] : 1;

        if ( $numerator != 0 && $denominator != 0 ) {
            if ( $numerator < $denominator ) {
                $new_denominator = round( $denominator / $numerator );
                return '1/' . $new_denominator . ' ' . etp_trans( 'sec' );
            } else {
                $val = $numerator / $denominator;
                return ( is_int($val) ? $val : round($val, 1) ) . ' ' . etp_trans( 'sec' );
            }
        }
    }
    return $fraction . ' ' . etp_trans( 'sec' );
}

/**
 * Helper: Convert GPS DMS (Degree, Minute, Second) to Decimal.
 * * @param array $dms_array Array of DMS rationals.
 * @param string $ref Reference (N, S, E, W).
 * @return float Decimal coordinate.
 */
function etp_gps_to_decimal( $dms_array, $ref ) {
    if ( ! is_array( $dms_array ) || count( $dms_array ) < 3 ) {
        return 0;
    }

    $degrees = etp_convert_fraction( $dms_array[0] );
    $minutes = etp_convert_fraction( $dms_array[1] );
    $seconds = etp_convert_fraction( $dms_array[2] );

    $decimal = $degrees + ( $minutes / 60 ) + ( $seconds / 3600 );

    // If South or West, make negative
    if ( strtoupper( $ref ) === 'S' || strtoupper( $ref ) === 'W' ) {
        $decimal *= -1;
    }

    return $decimal;
}

