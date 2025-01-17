<?php

namespace SLCA\EasyDigitalDownloads;

use wpCloud\StatelessMedia\Compatibility;

/**
 * Class EasyDigitalDownloads
 */
class EasyDigitalDownloads extends Compatibility {
  protected $id = 'edd-download-method';
  protected $title = 'Easy Digital Downloads';
  protected $constant = 'WP_STATELESS_COMPATIBILITY_EDD';
  protected $description = 'Ensures compatibility with the forced download method and WP-Stateless.';
  protected $plugin_file = 'easy-digital-downloads/easy-digital-downloads.php';
  protected $sm_mode_not_supported = ['stateless'];

  /**
   * @param $sm
   */
  public function module_init( $sm ) {
    add_action('edd_process_download_headers', array($this, 'edd_download_method_support'), 10, 4);

    // the main filter to replace url with GCS url have 20 as priority in Bootstrap class.
    // FES Author Avatar need local file to work.
    add_filter('wp_get_attachment_url', array($this, 'wp_get_attachment_url'), 30, 2);
  }

  /**
   * If EDD download method is Forced (direct) and file goes from GCS then make it to be downloaded right away.
   *
   * @param $requested_file
   * @param $download
   * @param $email
   * @param $payment
   */
  public function edd_download_method_support($requested_file, $download, $email, $payment) {
    if (!function_exists('edd_is_local_file') || !function_exists('edd_get_file_download_method')) return;
    if (edd_get_file_download_method() != 'direct') return;
    if (!edd_is_local_file($requested_file) && strstr($requested_file, ud_get_stateless_media()->get_gs_host())) {
      try {
        $file_extension = edd_get_file_extension($requested_file);
        $ctype = edd_get_file_ctype($file_extension);

        header("Content-Type: $ctype");
        header("Content-Transfer-Encoding: Binary");
        header("Content-Description: File Transfer");
        header("Content-disposition: attachment; filename=\"" . apply_filters('edd_requested_file_name', basename($requested_file)) . "\"");

        global $wp_filesystem;

        if (isset($wp_filesystem)) {
          $wp_filesystem->put_contents( 'php://output',  $wp_filesystem->get_contents($requested_file) );
        }

        if ( !defined('SLCA_RUNNING_TESTS') ) {
          exit;
        }
      } catch (Exception $e) {
        if (wp_redirect($requested_file)) {
          exit;
        }
      }
    }
  }

  /**
   * EDD Front-end Submission Author Avatar
   *
   * @param $url
   * @param $ID
   * @return string
   */
  public function wp_get_attachment_url($url, $ID) {
    global $wp_current_filter;

    // Verifying that the wp_get_attachment_url is called from EDD Front-end Submission.
    // The flow of function call
    // save_form_frontend() > save_field_values() > save_field() >
    // save_field_frontend() > fes_update_avatar() > wp_get_image_editor()
    if (in_array('wp_ajax_fes_submit_profile_form', $wp_current_filter)) {
      $uploads = wp_get_upload_dir();
      $meta_data = wp_get_attachment_metadata($ID);

      if (!empty($meta_data['file']) && false === $uploads['error']) {
        $absolutePath = $uploads['basedir'] . "/" . $meta_data['file'];

        if (!file_exists($absolutePath)) {
          $this->client = ud_get_stateless_media()->get_client();
          if ($this->client && !is_wp_error($this->client)) {
            $this->client->get_media($meta_data['file'], true, $absolutePath);
          }
        }

        if (file_exists($absolutePath)) {
          $url = $uploads['baseurl'] . "/" . $meta_data['file'];
        }
      }
    }
    return $url;
  }
}
