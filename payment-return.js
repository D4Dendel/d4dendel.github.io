/* A redirect is never proof of payment. Only the owned server state controls UX. */
(() => {
  const orderId = Number(new URLSearchParams(location.search).get('order'));
  const cancelled = new URLSearchParams(location.search).get('outcome') === 'cancel';
  const status = document.querySelector('[data-payment-return-status]');
  const retry = document.querySelector('[data-payment-return-retry]');
  const recheck = document.querySelector('[data-payment-return-recheck]');
  let checks = 0;
  let timer;
  let checking = false;
  const clearPurchasedItems = async (state) => {
    if (state.paymentStatus !== 'paid' || state.orderId !== orderId) return;
    const clear = () => {
      const receiptKey = `dyndelPaidCartCleared:${orderId}`;
      if (localStorage.getItem(receiptKey)) return;
      const snapshot = JSON.parse(sessionStorage.getItem(`dyndelPaymentCart:${orderId}`) || 'null');
      if (!Array.isArray(snapshot)) return; // Another device/tab must not clear an unrelated Cart.
      const bought = new Map(state.purchasedItems.map((item) => [item.id, item.quantity]));
      const original = new Map(snapshot.map((item) => [item.id, item.quantity]));
      const current = normalizeCartData(getStoredData(CART_KEY, []));
      cart = current.flatMap((item) => {
        const quantity = item.quantity - Math.min(original.get(item.id) || 0, bought.get(item.id) || 0);
        return quantity > 0 ? [{ id: item.id, quantity }] : [];
      });
      saveCart();
      localStorage.setItem(receiptKey, 'paid');
      sessionStorage.removeItem(`dyndelPaymentCart:${orderId}`);
      sessionStorage.removeItem('dyndelCheckoutAttemptToken');
    };
    if (navigator.locks) await navigator.locks.request('dyndel-paid-cart', clear);
    else clear();
  };
  const check = async () => {
    if (checking) return;
    if (!Number.isSafeInteger(orderId) || orderId < 1) { status.textContent = 'This payment return has no valid order reference.'; return; }
    checking = true;
    checks++;
    try {
      const { state } = await cmsRequest(`payment-order-state&orderId=${encodeURIComponent(orderId)}`);
      if (state.paymentStatus === 'paid') {
        status.textContent = 'Payment received. Your order is paid.';
        retry.hidden = true;
        recheck.hidden = true;
        await clearPurchasedItems(state);
        return;
      }
      if (cancelled || ['cancelled', 'failed'].includes(state.attemptStatus)) {
        status.textContent = 'Payment was not completed. Your order remains unpaid and your Cart is unchanged.';
      } else {
        status.textContent = checks < 12 ? 'Confirming your payment…' : 'Confirmation is still pending. Recheck shortly; your Cart is unchanged.';
        if (checks < 12) timer = setTimeout(check, 2000);
      }
      retry.hidden = !sessionStorage.getItem(`dyndelPaymentContext:${orderId}`);
    } catch (error) {
      status.textContent = 'Payment status could not be verified in this checkout session. Your Cart is unchanged.';
    } finally { checking = false; }
  };
  recheck.addEventListener('click', () => { clearTimeout(timer); checks = 0; check(); });
  retry.addEventListener('click', async () => {
    retry.disabled = true;
    try {
      const context = JSON.parse(sessionStorage.getItem(`dyndelPaymentContext:${orderId}`) || 'null');
      const { session } = await cmsRequest('stripe-checkout-session', { method: 'POST', body: new URLSearchParams({ orderId: String(orderId), csrfToken: context?.csrfToken || '' }) });
      if (session.alreadyPaid) { checks = 0; await check(); return; }
      const destination = new URL(session.url);
      if (destination.protocol !== 'https:' || destination.hostname !== 'checkout.stripe.com' || destination.username || destination.password || destination.port) throw new Error('Invalid payment destination.');
      location.assign(destination.href);
    } catch (error) { status.textContent = error.message || 'Payment is unavailable. Your Cart is unchanged.'; }
    finally { retry.disabled = false; }
  });
  window.addEventListener('pagehide', () => clearTimeout(timer));
  check();
})();
