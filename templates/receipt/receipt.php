<?php
/**
 * Order Receipt template (C12) — shared by the PDF (Dompdf) and the hosted page.
 *
 * Mockup: .mockups/order_receipt.html. The mockup uses CSS grid/flex for its
 * multi-column layout, but Dompdf (the PDF engine) supports neither — so this
 * port is TABLE-BASED to render identically in the PDF and the browser. Colors,
 * typography, and section order follow the mockup; the <style> block is read
 * directly by Dompdf (no Emogrifier inlining needed for the receipt).
 *
 * Consumes a pre-computed $ctx array built by
 * EEM_Shortcodes::build_receipt_html(). Values are escaped here as defense in
 * depth.
 *
 * @var array $ctx
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ctx = isset( $ctx ) && is_array( $ctx ) ? $ctx : array();
$c   = function ( $key, $default = '' ) use ( $ctx ) {
	return isset( $ctx[ $key ] ) ? $ctx[ $key ] : $default;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( sprintf( /* translators: %s: order number, e.g. #00020. */ __( 'Order Receipt %s', 'equine-event-manager' ), $c( 'order_number' ) ) ); ?></title>
<!-- Web (hosted) view loads the brand fonts; Dompdf ignores this and falls back
     to DejaVu Sans (bundled). Brand-exact PDF fonts are bundled in C12 increment 3. -->
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'IBM Plex Sans','DejaVu Sans','Helvetica Neue',Helvetica,Arial,sans-serif; color: #1d2327; background: #fff; font-size: 12.5px; line-height: 1.5; }
/* max-width fits a Letter page's printable area (~554pt) so Dompdf doesn't clip
   the right edge; @page margin handles the PDF insets. Web view is centered. */
.sheet { width: 100%; max-width: 700px; margin: 0 auto; padding: 22px 24px; }
a { color: #1668F2; text-decoration: none; }
.navy { color: #031B4E; }
.muted { color: #50575e; }
h1,h2,h3 { font-family: 'Space Grotesk','DejaVu Sans','Helvetica Neue',Arial,sans-serif; }
/* Section/title classes also use the display face. */
.event-name,.receipt-tag,.dblock-title,.section-label,.assignments-title,.rct,.tbl-label,.special-note-label,.cancellation-policy-title,.totals-inner tr.grand td { font-family: 'Space Grotesk','DejaVu Sans','Helvetica Neue',Arial,sans-serif; }

/* Header */
.header-table { width: 100%; border-bottom: 2px solid #031B4E; padding-bottom: 16px; margin-bottom: 16px; }
.header-table td { vertical-align: top; }
.logo-img { max-width: 160px; max-height: 36px; display: block; margin-bottom: 10px; }
.receipt-tag { font-size: 12px; font-weight: 600; color: #1668F2; margin-bottom: 4px; }
.event-name { font-size: 20px; font-weight: 700; color: #031B4E; margin-bottom: 3px; line-height: 1.2; }
.event-sub { font-size: 12px; color: #50575e; }
.order-box { background: #F0F4FB; border: 1px solid #D9E2F2; border-radius: 4px; padding: 12px 16px; }
.order-box .obl { font-size: 11.5px; font-weight: 600; color: #50575e; }
.order-box .obv { font-size: 13.5px; font-weight: 700; color: #031B4E; margin-bottom: 8px; }
.order-box .obv.paid { font-size: 17px; color: #1668F2; margin-bottom: 0; }
.receipt-status-pill { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; background: #FCEAEA; color: #B3261E; border: 1px solid #E6A6A0; }

/* Refund / void status banner */
.refund-banner { margin: 0 0 16px; padding: 12px 16px; border-radius: 4px; background: #FCEAEA; border: 1px solid #E6A6A0; color: #7A1A12; font-size: 13px; }
.refund-banner-label { font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.refund-banner-detail { margin-left: 6px; }
.totals-inner tr.refunded-row td { color: #B3261E; font-weight: 700; }

/* Customer + Billing */
.details-table { width: 100%; margin-bottom: 16px; border-spacing: 0; table-layout: fixed; }
.details-table > tbody > tr > td { width: 50%; vertical-align: top; }
.details-table td.dleft { padding-right: 6px; }
.details-table td.dright { padding-left: 6px; }
.dblock { background: #f3f4f5; border: 1px solid #e5e7eb; border-radius: 4px; padding: 12px 14px; }
.dblock-title { font-size: 13px; font-weight: 700; color: #031B4E; margin-bottom: 9px; }
.dl { font-size: 11.5px; font-weight: 600; color: #50575e; }
.dv { font-size: 13px; font-weight: 500; color: #1d2327; margin-bottom: 6px; }
.gap { width: 12px; }

/* Section label */
.section-label { font-size: 13px; font-weight: 700; color: #031B4E; margin-bottom: 8px; }

/* Assignments */
.assignments { background: #F0F4FB; border: 1px solid #D9E2F2; border-radius: 4px; padding: 12px 14px; margin-bottom: 16px; }
.assignments-title { font-size: 13px; font-weight: 700; color: #031B4E; margin-bottom: 8px; }
.assignments table { width: 100%; border-collapse: collapse; }
.assignments td { vertical-align: top; padding: 5px 0; border-bottom: 1px solid #E5EAF2; font-size: 12.5px; }
.assignments tr:last-child td { border-bottom: none; }
.assign-label { font-weight: 700; color: #031B4E; width: 90px; }
.assign-nights { color: #50575e; font-size: 12px; text-align: right; }

/* Reservation summary cards */
.cards-table { width: 100%; margin-bottom: 16px; border-spacing: 0; }
.cards-table > tbody > tr > td { vertical-align: top; padding-bottom: 10px; }
.res-card { border: 1px solid #e5e7eb; border-radius: 4px; }
.rch { background: #f3f4f5; border-bottom: 1px solid #dcdcde; padding: 8px 12px; }
.rct { font-size: 13px; font-weight: 700; color: #031B4E; }
.rcb { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 3px; background: #EEF4FF; color: #1668F2; border: 1px solid #c0d8ff; float: right; }
.rcb.rv { background: #F5F3FF; color: #6d28d9; border-color: #ddd6fe; }
.rcb.group { background: #F0FDF4; color: #15803d; border-color: #bbf7d0; }
.rcbody { padding: 10px 12px; }
.rcbody table { width: 100%; border-collapse: collapse; }
.rcbody td { width: 50%; vertical-align: top; padding: 3px 0; }
.rfl { font-size: 11.5px; font-weight: 600; color: #50575e; }
.rfv { font-size: 12.5px; font-weight: 500; color: #1d2327; }

/* Line items */
.tbl-label { font-size: 13px; font-weight: 700; color: #031B4E; margin-bottom: 8px; }
.items-table { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-bottom: 16px; }
.items-table thead tr { background: #f3f4f5; }
.items-table thead th { padding: 8px 10px; font-size: 11.5px; font-weight: 600; color: #1d2327; text-align: left; border-bottom: 1px solid #dcdcde; }
.items-table thead th.c { text-align: center; }
.items-table thead th.r { text-align: right; }
.items-table tbody td { padding: 7px 10px; font-size: 12px; border-bottom: 1px solid #f0f0f1; }
.items-table tbody tr:last-child td { border-bottom: none; }
.items-table tbody tr:nth-child(even) td { background: #f9f9f9; }
.ts { font-size: 11.5px; font-weight: 600; color: #50575e; }
.tc { text-align: center; color: #50575e; }
.tr-cell { text-align: right; font-weight: 700; color: #031B4E; }

/* Special requests */
.special-note { background: #F0F4FB; border: 1px solid #D9E2F2; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; }
.special-note-label { font-size: 12.5px; font-weight: 700; color: #031B4E; margin-bottom: 3px; }
.special-note-text { font-size: 12.5px; color: #1d2327; }

/* Totals */
.totals-table { width: 100%; margin-bottom: 16px; border-spacing: 0; }
.totals-inner { width: 280px; float: right; }
.totals-inner table { width: 100%; border-collapse: collapse; }
.totals-inner td { padding: 5px 0; font-size: 12.5px; border-bottom: 1px solid #f0f0f1; }
.totals-inner td.tl { color: #50575e; }
.totals-inner td.tv { text-align: right; font-weight: 600; color: #1d2327; }
.totals-inner tr.subtotal td { font-weight: 700; color: #031B4E; }
.totals-inner tr.grand td { border-top: 2px solid #031B4E; border-bottom: none; padding-top: 8px; font-family: 'Space Grotesk',Arial,sans-serif; }
.totals-inner tr.grand td.tl { font-weight: 800; color: #031B4E; font-size: 14px; }
.totals-inner tr.grand td.tv { font-weight: 800; color: #1668F2; font-size: 15px; }
.clear { clear: both; }

/* Cancellation */
.cancellation-policy { background: #f3f4f5; border: 1px solid #e5e7eb; border-radius: 4px; padding: 12px 14px; margin-bottom: 16px; }
.cancellation-policy-title { font-size: 13px; font-weight: 700; color: #031B4E; margin-bottom: 5px; }
.cancellation-policy-body { font-size: 11.5px; line-height: 1.55; color: #50575e; }

/* Footer — fixed/running footer repeats on every PDF page (Dompdf); the web view
   shows a normal in-flow footer instead (toggled by media type). */
.page-footer { position: fixed; bottom: 0.22in; left: 0.5in; right: 0.5in; border-top: 1px solid #e5e7eb; padding-top: 7px; color: #50575e; font-size: 10px; }
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td { vertical-align: middle; }
.page-footer td.right { text-align: right; }
.footer-inflow { border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 8px; color: #50575e; font-size: 11.5px; }
.footer-inflow table { width: 100%; }
.footer-inflow td { vertical-align: middle; }
.footer-inflow td.right { text-align: right; }
@media screen { .page-footer { display: none; } }
@media print { .footer-inflow { display: none; } }
/* Bottom margin reserves room for the fixed footer. */
@page { size: letter; margin: 0.5in 0.5in 0.7in 0.5in; }
</style>
</head>
<body>
<?php
// Footer content (shared by the fixed PDF page-footer and the web in-flow footer).
$eem_support_parts = array();
if ( $c( 'support_phone' ) ) { $eem_support_parts[] = $c( 'support_phone' ); }
if ( $c( 'support_email' ) ) { $eem_support_parts[] = $c( 'support_email' ); }
$eem_support_line = $eem_support_parts ? __( 'Support:', 'equine-event-manager' ) . ' ' . implode( '  |  ', $eem_support_parts ) : '';
$eem_footer_right = trim( $c( 'order_number' ) . ( $c( 'event_title' ) ? '  ·  ' . $c( 'event_title' ) : '' ) );
?>
<div class="page-footer"><table><tbody><tr>
  <td><?php echo esc_html( $eem_support_line ); ?></td>
  <td class="right"><?php echo esc_html( $eem_footer_right ); ?></td>
</tr></tbody></table></div>
<div class="sheet">

  <!-- HEADER -->
  <table class="header-table"><tbody><tr>
    <td>
      <?php if ( $c( 'logo_url' ) ) : ?>
        <?php // Empty alt: if the image fails to load in the PDF, it renders nothing
        // rather than dumping the event title into the header. Brand logo is embedded
        // as a data URI for the PDF in C12 increment 3. ?>
<?php // esc_url with the data protocol allowed — the PDF embeds the logo as a data: URI (we generate it from a local file), which esc_url() strips by default. ?>
        <img class="logo-img" src="<?php echo esc_url( $c( 'logo_url' ), array( 'http', 'https', 'data' ) ); ?>" alt="">
      <?php endif; ?>
      <div class="receipt-tag"><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Order Receipt %s', 'equine-event-manager' ), $c( 'order_number' ) ) ); ?></div>
      <div class="event-name"><?php echo esc_html( $c( 'event_title' ) ); ?></div>
      <?php if ( $c( 'event_sub' ) ) : ?><div class="event-sub"><?php echo esc_html( $c( 'event_sub' ) ); ?></div><?php endif; ?>
    </td>
    <td style="text-align:right;width:190px;">
      <div class="order-box" style="text-align:right;">
        <div class="obl"><?php esc_html_e( 'Order Number', 'equine-event-manager' ); ?></div>
        <div class="obv"><?php echo esc_html( $c( 'order_number' ) ); ?></div>
        <?php if ( $c( 'payment_date' ) ) : ?>
          <div class="obl"><?php esc_html_e( 'Payment Date', 'equine-event-manager' ); ?></div>
          <div class="obv"><?php echo esc_html( $c( 'payment_date' ) ); ?></div>
        <?php endif; ?>
        <div class="obl"><?php esc_html_e( 'Amount Paid', 'equine-event-manager' ); ?></div>
        <div class="obv paid"><?php echo esc_html( $c( 'amount_paid' ) ); ?></div>
        <?php if ( $c( 'status_label' ) ) : ?>
          <div class="receipt-status-pill"><?php echo esc_html( $c( 'status_label' ) ); ?></div>
        <?php endif; ?>
      </div>
    </td>
  </tr></tbody></table>

  <?php if ( $c( 'is_refunded' ) ) : ?>
    <!-- REFUND / VOID STATUS BANNER -->
    <div class="refund-banner">
      <span class="refund-banner-label"><?php echo esc_html( $c( 'status_label' ) ); ?></span>
      <?php if ( $c( 'refunded_amount' ) ) : ?>
        <span class="refund-banner-detail"><?php echo esc_html( sprintf( /* translators: %s: refunded amount, e.g. $2.00. */ __( '%s was returned to the original payment method.', 'equine-event-manager' ), $c( 'refunded_amount' ) ) ); ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- CUSTOMER + BILLING -->
  <table class="details-table"><tbody><tr>
    <td class="dleft">
      <div class="dblock">
        <div class="dblock-title"><?php esc_html_e( 'Customer Details', 'equine-event-manager' ); ?></div>
        <div class="dl"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></div>
        <div class="dv"><?php echo esc_html( $c( 'customer_name' ) ); ?></div>
        <?php if ( $c( 'reservation_type' ) ) : ?>
          <div class="dl"><?php esc_html_e( 'Reservation Type', 'equine-event-manager' ); ?></div>
          <div class="dv"><?php echo esc_html( $c( 'reservation_type' ) ); ?></div>
        <?php endif; ?>
        <?php if ( $c( 'customer_email' ) ) : ?>
          <div class="dl"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></div>
          <div class="dv"><?php echo esc_html( $c( 'customer_email' ) ); ?></div>
        <?php endif; ?>
        <?php if ( $c( 'customer_phone' ) ) : ?>
          <div class="dl"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></div>
          <div class="dv"><?php echo esc_html( $c( 'customer_phone' ) ); ?></div>
        <?php endif; ?>
      </div>
    </td>
    <td class="dright">
      <div class="dblock">
        <div class="dblock-title"><?php esc_html_e( 'Billing Details', 'equine-event-manager' ); ?></div>
        <?php if ( $c( 'billing_address' ) ) : ?>
          <div class="dv"><?php echo wp_kses( nl2br( esc_html( $c( 'billing_address' ) ) ), array( 'br' => array() ) ); ?></div>
        <?php else : ?>
          <div class="dv muted"><?php esc_html_e( 'No billing address on file.', 'equine-event-manager' ); ?></div>
        <?php endif; ?>
      </div>
    </td>
  </tr></tbody></table>

  <!-- ASSIGNMENTS (omitted when nothing assigned) -->
  <?php $assignments = (array) $c( 'assignments', array() ); ?>
  <?php if ( ! empty( $assignments ) ) : ?>
    <div class="assignments">
      <div class="assignments-title"><?php esc_html_e( 'Your Assignments', 'equine-event-manager' ); ?></div>
      <table><tbody>
        <?php foreach ( $assignments as $row ) : ?>
          <tr>
            <td class="assign-label"><?php echo esc_html( isset( $row['label'] ) ? $row['label'] : '' ); ?></td>
            <td><?php echo wp_kses( isset( $row['value'] ) ? $row['value'] : '', array( 'br' => array() ) ); ?></td>
            <td class="assign-nights"><?php echo wp_kses( isset( $row['nights'] ) ? $row['nights'] : '', array( 'br' => array() ) ); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  <?php endif; ?>

  <!-- RESERVATION SUMMARY CARDS -->
  <?php $cards = (array) $c( 'cards', array() ); ?>
  <?php if ( ! empty( $cards ) ) : ?>
    <div class="section-label"><?php esc_html_e( 'Reservation Summary', 'equine-event-manager' ); ?></div>
    <table class="cards-table"><tbody>
      <?php
      $col = 0;
      foreach ( $cards as $card ) :
        $full  = ! empty( $card['full'] );
        $rows  = isset( $card['rows'] ) && is_array( $card['rows'] ) ? $card['rows'] : array();
        if ( 0 === $col ) {
          echo '<tr>';
        }
        $colspan = $full ? ' colspan="3"' : '';
        ?>
        <td<?php echo $colspan; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static. ?>>
          <div class="res-card">
            <div class="rch">
              <span class="rct"><?php echo esc_html( isset( $card['title'] ) ? $card['title'] : '' ); ?></span>
              <?php if ( ! empty( $card['badge'] ) ) : ?>
                <span class="rcb <?php echo esc_attr( isset( $card['badge_class'] ) ? $card['badge_class'] : '' ); ?>"><?php echo esc_html( $card['badge'] ); ?></span>
              <?php endif; ?>
            </div>
            <div class="rcbody">
              <table><tbody>
                <?php
                $rc = count( $rows );
                for ( $i = 0; $i < $rc; $i += 2 ) :
                  ?>
                  <tr>
                    <td>
                      <div class="rfl"><?php echo esc_html( $rows[ $i ]['label'] ); ?></div>
                      <div class="rfv"><?php echo esc_html( $rows[ $i ]['value'] ); ?></div>
                    </td>
                    <td>
                      <?php if ( isset( $rows[ $i + 1 ] ) ) : ?>
                        <div class="rfl"><?php echo esc_html( $rows[ $i + 1 ]['label'] ); ?></div>
                        <div class="rfv"><?php echo esc_html( $rows[ $i + 1 ]['value'] ); ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody></table>
            </div>
          </div>
        </td>
        <?php
        if ( $full ) {
          echo '</tr>';
          $col = 0;
        } elseif ( 0 === $col ) {
          echo '<td class="gap"></td>';
          $col = 1;
        } else {
          echo '</tr>';
          $col = 0;
        }
      endforeach;
      if ( 1 === $col ) {
        echo '<td></td></tr>';
      }
      ?>
    </tbody></table>
  <?php endif; ?>

  <!-- LINE ITEMS -->
  <?php $line_items = (array) $c( 'line_items', array() ); ?>
  <?php if ( ! empty( $line_items ) ) : ?>
    <div class="tbl-label"><?php esc_html_e( 'Purchased Items', 'equine-event-manager' ); ?></div>
    <table class="items-table">
      <thead><tr>
        <th><?php esc_html_e( 'Section', 'equine-event-manager' ); ?></th>
        <th><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></th>
        <th class="c"><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
        <th class="c"><?php esc_html_e( 'Units', 'equine-event-manager' ); ?></th>
        <th class="c"><?php esc_html_e( 'Rate', 'equine-event-manager' ); ?></th>
        <th class="r"><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ( $line_items as $item ) : ?>
          <tr>
            <td class="ts"><?php echo esc_html( isset( $item['section'] ) ? $item['section'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $item['desc'] ) ? $item['desc'] : '' ); ?></td>
            <td class="tc"><?php echo esc_html( isset( $item['qty'] ) ? $item['qty'] : '' ); ?></td>
            <td class="tc"><?php echo esc_html( isset( $item['units'] ) ? $item['units'] : '' ); ?></td>
            <td class="tc"><?php echo esc_html( isset( $item['rate'] ) ? $item['rate'] : '' ); ?></td>
            <td class="tr-cell"><?php echo esc_html( isset( $item['total'] ) ? $item['total'] : '' ); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- SPECIAL REQUESTS -->
  <?php if ( $c( 'special_requests' ) ) : ?>
    <div class="special-note">
      <div class="special-note-label"><?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></div>
      <div class="special-note-text"><?php echo wp_kses( nl2br( esc_html( $c( 'special_requests' ) ) ), array( 'br' => array() ) ); ?></div>
    </div>
  <?php endif; ?>

  <!-- TOTALS -->
  <table class="totals-table"><tbody><tr><td>
    <div class="totals-inner">
      <table><tbody>
        <?php foreach ( (array) $c( 'totals', array() ) as $line ) : ?>
          <tr><td class="tl"><?php echo esc_html( $line['label'] ); ?></td><td class="tv"><?php echo esc_html( $line['value'] ); ?></td></tr>
        <?php endforeach; ?>
        <tr class="subtotal"><td class="tl"><?php esc_html_e( 'Subtotal', 'equine-event-manager' ); ?></td><td class="tv"><?php echo esc_html( $c( 'subtotal' ) ); ?></td></tr>
        <?php if ( $c( 'fee' ) ) : ?>
          <tr><td class="tl"><?php esc_html_e( 'Non-Refundable Convenience Fee', 'equine-event-manager' ); ?></td><td class="tv"><?php echo esc_html( $c( 'fee' ) ); ?></td></tr>
        <?php endif; ?>
        <?php if ( $c( 'tax' ) ) : ?>
          <tr><td class="tl"><?php echo esc_html( sprintf( /* translators: %s: tax rate, e.g. 7.5%%. */ __( 'Sales Tax (%s)', 'equine-event-manager' ), $c( 'tax_rate_label' ) ) ); ?></td><td class="tv"><?php echo esc_html( $c( 'tax' ) ); ?></td></tr>
        <?php endif; ?>
        <?php if ( $c( 'is_refunded' ) && $c( 'refunded_amount' ) ) : ?>
          <tr class="grand"><td class="tl"><?php esc_html_e( 'Order Total', 'equine-event-manager' ); ?></td><td class="tv"><?php echo esc_html( $c( 'grand_total' ) ); ?></td></tr>
          <tr class="refunded-row"><td class="tl"><?php esc_html_e( 'Refunded', 'equine-event-manager' ); ?></td><td class="tv">&minus;<?php echo esc_html( $c( 'refunded_amount' ) ); ?></td></tr>
          <tr class="grand"><td class="tl"><?php esc_html_e( 'Net Paid', 'equine-event-manager' ); ?></td><td class="tv"><?php echo esc_html( $c( 'net_paid' ) ); ?></td></tr>
        <?php else : ?>
          <tr class="grand"><td class="tl"><?php esc_html_e( 'Total Amount Paid', 'equine-event-manager' ); ?></td><td class="tv"><?php echo esc_html( $c( 'grand_total' ) ); ?></td></tr>
        <?php endif; ?>
      </tbody></table>
    </div>
    <div class="clear"></div>
  </td></tr></tbody></table>

  <!-- CANCELLATION POLICY -->
  <?php if ( $c( 'cancellation_policy' ) ) : ?>
    <div class="cancellation-policy">
      <div class="cancellation-policy-title"><?php esc_html_e( 'Cancellation Policy', 'equine-event-manager' ); ?></div>
      <div class="cancellation-policy-body"><?php echo wp_kses( nl2br( esc_html( $c( 'cancellation_policy' ) ) ), array( 'br' => array() ) ); ?></div>
    </div>
  <?php endif; ?>

  <!-- FOOTER (web in-flow; the PDF uses the fixed running footer above) -->
  <div class="footer-inflow"><table><tbody><tr>
    <td><?php echo esc_html( $eem_support_line ); ?></td>
    <td class="right"><?php echo esc_html( $eem_footer_right ); ?></td>
  </tr></tbody></table></div>

</div>
</body>
</html>
