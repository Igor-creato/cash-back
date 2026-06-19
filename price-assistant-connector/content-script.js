(function () {
  "use strict";

  window.addEventListener("message", function (event) {
    if (event.source !== window || event.origin !== window.location.origin) {
      return;
    }

    const message = event.data || {};
    if (message.type !== "cashback-price-assistant:captureSession" || !message.payload) {
      return;
    }

    chrome.runtime.sendMessage(message.payload, function (response) {
      window.postMessage(
        {
          type: "cashback-price-assistant:captureResult",
          payload: response || { ok: false, code: "connector_unavailable" },
        },
        window.location.origin
      );
    });
  });
})();
