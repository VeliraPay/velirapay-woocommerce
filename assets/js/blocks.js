/**
 * Adds VeliraPay to the WooCommerce block checkout. Plain JavaScript: no build step.
 */
(function (wc, wp) {
  if (!wc || !wc.wcBlocksRegistry || !wc.wcSettings || !wp || !wp.element) {
    return;
  }

  var createElement = wp.element.createElement;
  var decodeEntities = wp.htmlEntities.decodeEntities;
  var settings = wc.wcSettings.getSetting('velirapay_data', {});
  var title = decodeEntities(settings.title || '') || wp.i18n.__('Crypto (VeliraPay)', 'velirapay-for-woocommerce');
  var description = decodeEntities(settings.description || '');

  function Label(props) {
    var PaymentMethodLabel = props.components.PaymentMethodLabel;

    return createElement(
      'span',
      { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
      createElement(PaymentMethodLabel, { text: title }),
      settings.icon
        ? createElement('img', { src: settings.icon, alt: '', style: { height: '24px', width: '24px' } })
        : null
    );
  }

  function Content() {
    return createElement('div', null, description);
  }

  // VeliraPay takes nothing under its lowest price, about half a dollar, or under the cheapest coin the account takes,
  // so the method is left out when a coupon or a removed item takes the total below it. The server checks again.
  function reachesLowestAmount(args) {
    var totals = args && args.cartTotals;
    var lowest = parseFloat(settings.lowestAmount || '');

    if (!totals || isNaN(lowest) || totals.currency_code !== settings.currency) {
      return true;
    }

    var total = parseInt(totals.total_price, 10) / Math.pow(10, totals.currency_minor_unit || 0);

    return isNaN(total) || total <= 0 || total >= lowest;
  }

  wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'velirapay',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    ariaLabel: title,
    canMakePayment: reachesLowestAmount,
    supports: {
      features: settings.supports || ['products']
    }
  });
})(window.wc, window.wp);
