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
  var title = decodeEntities(settings.title || '') || wp.i18n.__('Crypto (VeliraPay)', 'velirapay-woocommerce');
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

  wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'velirapay',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    ariaLabel: title,
    canMakePayment: function () {
      return true;
    },
    supports: {
      features: settings.supports || ['products']
    }
  });
})(window.wc, window.wp);
