<?php
/**
 * Customer Confirmation Email template (C11).
 *
 * Mockup: .mockups/customer_confirmation_email.html. The <style> block is kept
 * inline-able here for design-time readability; EEM_Mailer::inline_css() runs
 * Emogrifier at send-time so the styles survive Outlook / Gmail / Yahoo (the
 * <style> tag alone is stripped by many clients). DO NOT hand-inline styles in
 * this file — author them in the <style> block and let the inliner do the work.
 *
 * Consumes a pre-computed $ctx array built by
 * EEM_Reservation_Shortcodes::build_confirmation_email_html(). Every value is
 * already escaped/sanitized at build time EXCEPT where echoed through esc_*()
 * here as defense in depth.
 *
 * @var array $ctx Template context. See build_confirmation_email_html().
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ctx = isset( $ctx ) && is_array( $ctx ) ? $ctx : array();

$c = function ( $key, $default = '' ) use ( $ctx ) {
	return isset( $ctx[ $key ] ) ? $ctx[ $key ] : $default;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( sprintf( /* translators: %s: event title. */ __( 'Reservation Confirmation – %s', 'equine-event-manager' ), $c( 'event_title' ) ) ); ?></title>
<!-- Styles are inlined at send-time via Emogrifier (EEM_Mailer::inline_css). -->
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#f0f2f5;font-family:'IBM Plex Sans','Helvetica Neue',Helvetica,Arial,sans-serif;color:#1a1a2e;font-size:14px;line-height:1.6;padding:24px 16px}
a{text-decoration:none}
a:hover{text-decoration:none}
.wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:4px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.email-header{background:#fff;padding:28px 36px;border-bottom:3px solid #031B4E}
.logo-img{max-width:160px;max-height:40px;display:block;margin-bottom:16px}
.header-event{font-size:22px;font-weight:700;color:#031B4E;margin-bottom:4px;font-family:'Space Grotesk',sans-serif;line-height:1.2;letter-spacing:-.01em}
.header-dates{font-size:13px;color:#50575e}
.confirm-bar{background:#F0F4FB;border-bottom:1px solid #D9E2F2;padding:14px 36px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.confirm-bar-left{display:flex;align-items:center;gap:12px}
.confirm-check{width:28px;height:28px;background:#1668F2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
.confirm-check svg{width:16px;height:16px}
.confirm-text{font-size:14px;font-weight:700;color:#031B4E}
.confirm-text-sub{font-weight:400;color:#50575e;font-size:13px;display:block;margin-top:2px}
.confirm-amount{text-align:right}
.confirm-amount-label{font-size:12px;color:#50575e;font-weight:600}
.confirm-amount-val{font-size:20px;font-weight:700;color:#1668F2}
.email-body{background:#fff;padding:28px 36px}
.greeting{font-size:15px;color:#1a1a2e;margin-bottom:18px;line-height:1.6}
.greeting strong{color:#031B4E}
.greeting a{color:#1668F2;font-weight:600}
.order-meta{display:flex;gap:6px;margin-bottom:24px;flex-wrap:wrap}
.type-badge{padding:3px 9px;border-radius:3px;font-size:11.5px;font-weight:600;white-space:nowrap;display:inline-block;line-height:1.5}
.type-stall{background:#EEF4FF;color:#1668F2;border:1px solid #c0d8ff}
.type-rv{background:#F5F3FF;color:#6d28d9;border:1px solid #ddd6fe}
.type-addon{background:#FFF7ED;color:#c2410c;border:1px solid #fed7aa}
.type-group{background:#F0FDF4;color:#15803d;border:1px solid #bbf7d0}
.assignments-section{background:#F0F4FB;border:1px solid #D9E2F2;border-radius:4px;padding:14px 16px;margin:0 0 22px}
.assignments-title{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;color:#031B4E;margin-bottom:10px}
.assignments-table{width:100%;border-collapse:collapse}
.assignments-table td{vertical-align:top;padding:6px 0;border-bottom:1px solid #E5EAF2;font-size:13px;line-height:1.55}
.assignments-table tr:last-child td{border-bottom:none}
.assign-label{font-weight:700;color:#031B4E;width:80px;padding-right:14px}
.assign-value{color:#1d2327;font-weight:500}
.assign-nights{color:#50575e;font-size:12px;text-align:right;white-space:nowrap;padding-left:14px}
.hosted-link{display:inline-block;margin-top:10px;color:#1668F2;font-size:12.5px;font-weight:600}
.hosted-link:hover{color:#1257d1}
.items-title{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;color:#031B4E;margin-bottom:10px}
.items-table-wrap{margin-bottom:20px}
.items-table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:4px;overflow:hidden}
.items-table thead tr{background:#f3f4f5;border-bottom:1px solid #dcdcde}
.items-table thead th{padding:9px 10px;font-size:12px;font-weight:600;color:#1d2327;text-align:left}
.items-table thead th:last-child{text-align:right}
.items-table thead th.c{text-align:center}
.items-table tbody tr{border-bottom:1px solid #f0f0f1}
.items-table tbody tr:last-child{border-bottom:none}
.items-table td{padding:8px 10px;font-size:12.5px;color:#1d2327;vertical-align:middle}
.td-sec{font-size:11.5px;font-weight:600;color:#50575e;white-space:nowrap}
.td-c{text-align:center;color:#50575e}
.td-r{text-align:right;font-weight:700;color:#031B4E;font-variant-numeric:tabular-nums}
.totals-row{display:flex;justify-content:flex-end;margin-bottom:24px}
.totals-inner{min-width:240px;display:flex;justify-content:space-between;gap:32px;border-top:2px solid #031B4E;padding-top:12px}
.totals-label{font-size:14px;font-weight:700;color:#031B4E}
.totals-value{font-size:17px;font-weight:800;color:#1668F2;font-variant-numeric:tabular-nums}
.special-requests{background:#F0F4FB;border:1px solid #D9E2F2;border-radius:4px;padding:14px 16px;margin-bottom:22px}
.special-requests-title{font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:700;color:#031B4E;margin-bottom:4px}
.special-requests-body{font-size:13px;color:#1a1a2e;line-height:1.55}
.whats-next{background:#F0FDF4;border:1px solid #BBF7D0;border-radius:4px;padding:14px 16px;margin:0 0 22px}
.whats-next-head{display:flex;align-items:center;gap:8px;font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;color:#15803d;margin-bottom:10px}
.whats-next-head svg{width:15px;height:15px;color:#15803d;flex-shrink:0}
.whats-next-body p{font-size:13px;line-height:1.6;color:#1d2327;margin:0 0 7px}
.whats-next-body p:last-child{margin-bottom:0}
.whats-next-body strong{color:#031B4E;font-weight:600}
.support-block{background:#f3f4f5;border:1px solid #e5e7eb;border-radius:4px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.support-left-title{display:block;font-size:13.5px;font-weight:700;color:#031B4E;margin-bottom:2px}
.support-left-sub{font-size:12.5px;color:#50575e}
.support-contact{text-align:right;font-size:13px;color:#1668F2;font-weight:600}
.support-contact-row{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-bottom:4px}
.support-contact-row:last-child{margin-bottom:0}
.support-contact-row svg{width:13px;height:13px;color:#1668F2;flex-shrink:0}
.support-contact-row a{color:#1668F2;font-weight:600}
.support-contact-row a:hover{color:#1257d1}
.cancellation-policy{background:#f3f4f5;border:1px solid #e5e7eb;border-radius:4px;padding:14px 16px;margin-bottom:22px}
.cancellation-policy-title{font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:700;color:#031B4E;margin-bottom:6px}
.cancellation-policy-body{font-size:12.5px;line-height:1.55;color:#50575e;margin:0}
.email-footer{background:#fff;border-top:1px solid #e5e7eb;padding:18px 36px;text-align:center}
.footer-event{font-size:13px;font-weight:600;color:#031B4E;margin-bottom:4px}
.footer-legal{font-size:11.5px;color:#8c8f94}
@media(max-width:640px){
  body{padding:0;background:#fff}
  .wrap{border-radius:0;box-shadow:none;max-width:100%}
  .email-header{padding:22px 20px}
  .email-body{padding:22px 20px}
  .confirm-bar{padding:12px 20px}
  .email-footer{padding:16px 20px}
  .header-event{font-size:19px}
}
@media(max-width:480px){
  .confirm-bar{flex-direction:column;align-items:flex-start;gap:10px}
  .confirm-amount{text-align:left}
  .confirm-amount-val{font-size:18px}
  .order-meta{gap:5px}
  .items-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px}
  .items-table{min-width:480px;font-size:11.5px}
  .items-table thead th,.items-table td{padding:7px 8px}
  .totals-row{justify-content:stretch}
  .totals-inner{min-width:0;width:100%}
  .support-block{flex-direction:column;align-items:flex-start;gap:10px;padding:12px}
  .support-contact{text-align:left}
  .support-contact-row{justify-content:flex-start}
  .assignments-table td{display:block;padding:3px 0;border:none}
  .assignments-table tr{display:block;padding:8px 0;border-bottom:1px solid #E5EAF2}
  .assignments-table tr:last-child{border-bottom:none}
  .assign-label{width:auto;font-size:12px;color:#50575e;margin-bottom:2px}
  .assign-nights{text-align:left;padding-left:0;margin-top:2px}
}
@media(max-width:360px){
  .email-header{padding:18px 14px}
  .email-body{padding:18px 14px}
  .confirm-bar{padding:10px 14px}
  .email-footer{padding:14px}
  .header-event{font-size:16px}
}
</style>
</head>
<body>
<div class="wrap">

  <div class="email-header">
    <?php if ( $c( 'logo_url' ) ) : ?>
      <img class="logo-img" src="<?php echo esc_url( $c( 'logo_url' ) ); ?>" alt="<?php echo esc_attr( $c( 'event_title' ) ); ?>">
    <?php endif; ?>
    <div class="header-event"><?php echo esc_html( $c( 'event_title' ) ); ?></div>
    <?php if ( $c( 'event_dates' ) ) : ?>
      <div class="header-dates"><?php echo esc_html( $c( 'event_dates' ) ); ?></div>
    <?php endif; ?>
  </div>

  <div class="confirm-bar">
    <div class="confirm-bar-left">
      <div class="confirm-check">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div>
        <div class="confirm-text"><?php echo esc_html( sprintf( /* translators: %s: order number, e.g. #00020. */ __( 'Order %s confirmed', 'equine-event-manager' ), $c( 'order_number' ) ) ); ?></div>
        <?php if ( $c( 'payment_date' ) ) : ?>
          <div class="confirm-text-sub"><?php echo esc_html( sprintf( /* translators: %s: payment date. */ __( 'Payment received on %s', 'equine-event-manager' ), $c( 'payment_date' ) ) ); ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="confirm-amount">
      <div class="confirm-amount-label"><?php esc_html_e( 'Amount Paid', 'equine-event-manager' ); ?></div>
      <div class="confirm-amount-val"><?php echo esc_html( $c( 'amount_paid' ) ); ?></div>
    </div>
  </div>

  <div class="email-body">

    <p class="greeting">
      <?php
      printf(
        /* translators: %s: customer first name (already wrapped in <strong>). */
        wp_kses( __( 'Hi %s, your reservation is confirmed and payment received. A full summary is below.', 'equine-event-manager' ), array( 'strong' => array() ) ),
        '<strong>' . esc_html( $c( 'customer_first', __( 'there', 'equine-event-manager' ) ) ) . '</strong>'
      );
      if ( $c( 'hosted_url' ) ) {
        echo ' ';
        printf(
          /* translators: %s: hosted order link (anchor). */
          wp_kses( __( 'You can %s anytime.', 'equine-event-manager' ), array( 'a' => array( 'href' => array() ) ) ),
          '<a href="' . esc_url( $c( 'hosted_url' ) ) . '">' . esc_html__( 'view your order online ↗', 'equine-event-manager' ) . '</a>'
        );
      }
      ?>
    </p>

    <?php $badges = (array) $c( 'badges', array() ); ?>
    <?php if ( array_filter( $badges ) ) : ?>
      <div class="order-meta">
        <?php if ( ! empty( $badges['stall'] ) ) : ?><span class="type-badge type-stall"><?php esc_html_e( 'Stall', 'equine-event-manager' ); ?></span><?php endif; ?>
        <?php if ( ! empty( $badges['rv'] ) ) : ?><span class="type-badge type-rv"><?php esc_html_e( 'RV', 'equine-event-manager' ); ?></span><?php endif; ?>
        <?php if ( ! empty( $badges['addon'] ) ) : ?><span class="type-badge type-addon"><?php esc_html_e( 'Add-On', 'equine-event-manager' ); ?></span><?php endif; ?>
        <?php if ( ! empty( $badges['group'] ) ) : ?><span class="type-badge type-group"><?php esc_html_e( 'Group', 'equine-event-manager' ); ?></span><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php $assignments = (array) $c( 'assignments', array() ); ?>
    <?php if ( ! empty( $assignments ) ) : ?>
      <div class="assignments-section">
        <div class="assignments-title"><?php esc_html_e( 'Your Assignments', 'equine-event-manager' ); ?></div>
        <table class="assignments-table" cellpadding="0" cellspacing="0">
          <tbody>
            <?php foreach ( $assignments as $row ) : ?>
              <tr>
                <td class="assign-label"><?php echo esc_html( isset( $row['label'] ) ? $row['label'] : '' ); ?></td>
                <td class="assign-value"><?php echo wp_kses( isset( $row['value'] ) ? $row['value'] : '', array( 'br' => array() ) ); ?></td>
                <td class="assign-nights"><?php echo wp_kses( isset( $row['nights'] ) ? $row['nights'] : '', array( 'br' => array() ) ); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ( $c( 'hosted_url' ) ) : ?>
          <a href="<?php echo esc_url( $c( 'hosted_url' ) ); ?>" class="hosted-link"><?php esc_html_e( 'View or print your order online ↗', 'equine-event-manager' ); ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php $line_items = (array) $c( 'line_items', array() ); ?>
    <?php if ( ! empty( $line_items ) ) : ?>
      <div class="items-title"><?php esc_html_e( 'Purchased Items', 'equine-event-manager' ); ?></div>
      <div class="items-table-wrap">
        <table class="items-table">
          <thead>
            <tr>
              <th><?php esc_html_e( 'Section', 'equine-event-manager' ); ?></th>
              <th><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></th>
              <th class="c"><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
              <th class="c"><?php esc_html_e( 'Units', 'equine-event-manager' ); ?></th>
              <th class="c"><?php esc_html_e( 'Rate', 'equine-event-manager' ); ?></th>
              <th><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $line_items as $item ) : ?>
              <tr>
                <td class="td-sec"><?php echo esc_html( isset( $item['section'] ) ? $item['section'] : '' ); ?></td>
                <td><?php echo esc_html( isset( $item['desc'] ) ? $item['desc'] : '' ); ?></td>
                <td class="td-c"><?php echo esc_html( isset( $item['qty'] ) ? $item['qty'] : '' ); ?></td>
                <td class="td-c"><?php echo esc_html( isset( $item['units'] ) ? $item['units'] : '' ); ?></td>
                <td class="td-c"><?php echo esc_html( isset( $item['rate'] ) ? $item['rate'] : '' ); ?></td>
                <td class="td-r"><?php echo esc_html( isset( $item['total'] ) ? $item['total'] : '' ); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="totals-row">
        <div class="totals-inner">
          <span class="totals-label"><?php esc_html_e( 'Total Paid', 'equine-event-manager' ); ?></span>
          <span class="totals-value"><?php echo esc_html( $c( 'total_paid' ) ); ?></span>
        </div>
      </div>
    <?php endif; ?>

    <?php if ( $c( 'special_requests' ) ) : ?>
      <div class="special-requests">
        <div class="special-requests-title"><?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></div>
        <div class="special-requests-body"><?php echo wp_kses( nl2br( esc_html( $c( 'special_requests' ) ) ), array( 'br' => array() ) ); ?></div>
      </div>
    <?php endif; ?>

    <?php $event_day = (array) $c( 'event_day', array() ); ?>
    <?php if ( array_filter( $event_day ) ) : ?>
      <div class="whats-next">
        <div class="whats-next-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span><?php esc_html_e( "What's Next — Event Day Info", 'equine-event-manager' ); ?></span>
        </div>
        <div class="whats-next-body">
          <?php if ( ! empty( $event_day['checkin'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Check-In/Check-Out Instructions:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $event_day['checkin'] ); ?></p>
          <?php endif; ?>
          <?php if ( ! empty( $event_day['bring'] ) ) : ?>
            <p><strong><?php esc_html_e( 'What to bring:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $event_day['bring'] ); ?></p>
          <?php endif; ?>
          <?php if ( ! empty( $event_day['parking'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Parking:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $event_day['parking'] ); ?></p>
          <?php endif; ?>
          <?php if ( ! empty( $event_day['contact'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Event Contact:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $event_day['contact'] ); ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ( $c( 'support_phone' ) || $c( 'support_email' ) ) : ?>
      <div class="support-block">
        <div>
          <span class="support-left-title"><?php esc_html_e( 'Questions about your reservation?', 'equine-event-manager' ); ?></span>
          <span class="support-left-sub"><?php esc_html_e( 'Our team is happy to help.', 'equine-event-manager' ); ?></span>
        </div>
        <div class="support-contact">
          <?php if ( $c( 'support_phone' ) ) : ?>
            <div class="support-contact-row">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
              <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', (string) $c( 'support_phone' ) ) ); ?>"><?php echo esc_html( $c( 'support_phone' ) ); ?></a>
            </div>
          <?php endif; ?>
          <?php if ( $c( 'support_email' ) ) : ?>
            <div class="support-contact-row">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <a href="mailto:<?php echo esc_attr( $c( 'support_email' ) ); ?>"><?php echo esc_html( $c( 'support_email' ) ); ?></a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ( $c( 'cancellation_policy' ) ) : ?>
      <div class="cancellation-policy">
        <div class="cancellation-policy-title"><?php esc_html_e( 'Cancellation Policy', 'equine-event-manager' ); ?></div>
        <p class="cancellation-policy-body"><?php echo wp_kses( nl2br( esc_html( $c( 'cancellation_policy' ) ) ), array( 'br' => array() ) ); ?></p>
      </div>
    <?php endif; ?>

  </div><!-- /email-body -->

  <div class="email-footer">
    <div class="footer-event"><?php echo esc_html( trim( $c( 'event_title' ) . ( $c( 'event_dates' ) ? ' · ' . $c( 'event_dates' ) : '' ) ) ); ?></div>
    <?php if ( $c( 'footer_legal' ) ) : ?>
      <div class="footer-legal"><?php echo esc_html( $c( 'footer_legal' ) ); ?></div>
    <?php endif; ?>
  </div>

</div><!-- /wrap -->
</body>
</html>
