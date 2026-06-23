<?php
/**
 * Generic tabular report PDF template (C15.E).
 *
 * Renders any report dataset ({title, headers, rows}) as a clean tabular PDF via
 * Dompdf. Table-based layout + the bundled brand fonts (registered in C12).
 *
 * @var array  $ctx Template context: { title, subtitle, generated, headers, rows }.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ctx     = isset( $ctx ) && is_array( $ctx ) ? $ctx : array();
$title   = isset( $ctx['title'] ) ? (string) $ctx['title'] : '';
$sub     = isset( $ctx['subtitle'] ) ? (string) $ctx['subtitle'] : '';
$gen     = isset( $ctx['generated'] ) ? (string) $ctx['generated'] : '';
$logo    = isset( $ctx['logo'] ) ? (string) $ctx['logo'] : '';
$headers = isset( $ctx['headers'] ) && is_array( $ctx['headers'] ) ? $ctx['headers'] : array();
$rows    = isset( $ctx['rows'] ) && is_array( $ctx['rows'] ) ? $ctx['rows'] : array();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $title ); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'IBM Plex Sans','DejaVu Sans',Helvetica,Arial,sans-serif; color: #1d2327; font-size: 9.5px; }
.sheet { padding: 8px 10px; }
.r-logo { max-width: 160px; max-height: 38px; display: block; margin-bottom: 10px; }
.r-title { font-family: 'IBM Plex Sans','DejaVu Sans',Arial,sans-serif; font-size: 17px; font-weight: 700; color: #031B4E; }
.r-sub { font-size: 10px; color: #50575e; margin-top: 2px; }
.r-gen { font-size: 9px; color: #8c8f94; margin-top: 2px; }
.r-head { border-bottom: 2px solid #031B4E; padding-bottom: 12px; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; }
thead tr { background: #f3f4f5; }
thead th { padding: 5px 6px; font-size: 9px; font-weight: 700; color: #1d2327; text-align: left; border-bottom: 1px solid #dcdcde; }
tbody td { padding: 4px 6px; font-size: 9px; color: #1d2327; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
tbody tr:nth-child(even) td { background: #f9f9f9; }
.r-empty { padding: 14px; font-size: 11px; color: #50575e; text-align: center; }
.r-foot { margin-top: 10px; font-size: 8.5px; color: #8c8f94; }
@page { size: letter portrait; margin: 0.5in 0.5in 0.7in 0.5in; }
</style>
</head>
<body>
<div class="sheet">
  <div class="r-head">
    <?php if ( '' !== $logo ) : ?>
      <?php // The PDF embeds the logo as a data: URI generated from a local file; esc_url() strips data: by default, so allow it explicitly. ?>
      <img class="r-logo" src="<?php echo esc_url( $logo, array( 'http', 'https', 'data' ) ); ?>" alt="">
    <?php endif; ?>
    <div class="r-title"><?php echo esc_html( $title ); ?></div>
    <?php if ( '' !== $sub ) : ?><div class="r-sub"><?php echo esc_html( $sub ); ?></div><?php endif; ?>
    <?php if ( '' !== $gen ) : ?><div class="r-gen"><?php echo esc_html( $gen ); ?></div><?php endif; ?>
  </div>

  <?php if ( empty( $rows ) ) : ?>
    <div class="r-empty"><?php esc_html_e( 'No data for the selected filters.', 'equine-event-manager' ); ?></div>
  <?php else : ?>
    <table>
      <thead><tr>
        <?php foreach ( $headers as $h ) : ?><th><?php echo esc_html( (string) $h ); ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ( $rows as $row ) : ?>
          <tr><?php foreach ( (array) $row as $cell ) : ?><td><?php echo esc_html( (string) $cell ); ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="r-foot"><?php echo esc_html( sprintf( /* translators: %d: row count. */ _n( '%d row', '%d rows', count( $rows ), 'equine-event-manager' ), count( $rows ) ) ); ?></div>
  <?php endif; ?>
</div>
</body>
</html>
