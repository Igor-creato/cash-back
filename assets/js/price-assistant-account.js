(function () {
  "use strict";

  const config = window.CashbackPriceAssistantAccount || {};
  const root = document.querySelector("[data-price-assistant-account]");
  if (!root || !config.restBase || !config.nonce) {
    return;
  }

  const marketplaceConfig = config.marketplaces || {};

  function requestJson(path, options) {
    const requestOptions = options || {};
    return fetch(config.restBase + path, {
      method: requestOptions.method || "GET",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": config.nonce,
      },
      body: requestOptions.body ? JSON.stringify(requestOptions.body) : undefined,
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          const code = data && data.code ? data.code : "request_failed";
          throw new Error(code);
        }
        return data;
      });
    });
  }

  function marketplaceFor(button) {
    const code = button.getAttribute("data-marketplace");
    return code && marketplaceConfig[code] ? marketplaceConfig[code] : null;
  }

  function pageUrl(marketplace, page) {
    return marketplace.page_urls && marketplace.page_urls[page]
      ? marketplace.page_urls[page]
      : "";
  }

  function setState(code, state) {
    const node = root.querySelector('[data-marketplace-state="' + code + '"]');
    if (node) {
      node.textContent = state;
    }
  }

  function openMarketplacePage(marketplace, page) {
    const url = pageUrl(marketplace, page);
    if (url) {
      window.open(url, "_blank", "noopener,noreferrer");
    }
  }

  function consentAccepted(marketplace) {
    const label = marketplace.label || marketplace.code;
    // eslint-disable-next-line no-alert -- Explicit consent is required before connector capture.
    return window.confirm(
      "Разрешить Price Assistant получить только утвержденные технические cookies/tokens для " +
        label +
        " после входа на настоящей странице маркетплейса?"
    );
  }

  function createConnection(code) {
    return requestJson("/connections", {
      method: "POST",
      body: {
        marketplace: code,
        consent_version: config.consentVersion,
        scope: config.scope || ["cart_read", "favorites_read"],
        captured_at: new Date().toISOString(),
        connector_version: "wordpress-account-0.1.0",
      },
    });
  }

  function connectionId(response) {
    return response.connection_id || response.id || response.marketplace_connection_id || null;
  }

  function requestConnectorCapture(marketplace, id) {
    window.postMessage(
      {
        type: "cashback-price-assistant:captureSession",
        payload: {
          action: config.connectorAction,
          restBase: config.restBase,
          nonce: config.nonce,
          connectionId: id,
          marketplace: marketplace.code,
          consent: true,
          consentVersion: config.consentVersion,
          scope: config.scope || ["cart_read", "favorites_read"],
          allowlist: marketplace.allowlist || { cookies: [], tokens: [] },
          hostPermissions: marketplace.host_permissions || [],
          pageUrls: marketplace.page_urls || {},
        },
      },
      window.location.origin
    );
  }

  root.addEventListener("click", function (event) {
    const button = event.target.closest("[data-marketplace]");
    if (!button) {
      return;
    }

    const marketplace = marketplaceFor(button);
    if (!marketplace || button.disabled) {
      return;
    }

    const page = button.getAttribute("data-marketplace-page") || "login";
    if (!button.classList.contains("cashback-price-assistant__connect")) {
      openMarketplacePage(marketplace, page);
      return;
    }

    if (!consentAccepted(marketplace)) {
      setState(marketplace.code, "disconnected");
      return;
    }

    button.disabled = true;
    setState(marketplace.code, "connected");
    createConnection(marketplace.code)
      .then(function (response) {
        const id = connectionId(response);
        openMarketplacePage(marketplace, page);
        if (id) {
          requestConnectorCapture(marketplace, id);
        }
      })
      .catch(function (error) {
        setState(
          marketplace.code,
          error && error.message === "marketplace_disabled"
            ? "disconnected"
            : "reconnect_required"
        );
      })
      .finally(function () {
        button.disabled = false;
      });
  });

  window.addEventListener("message", function (event) {
    if (event.origin !== window.location.origin) {
      return;
    }
    const message = event.data || {};
    if (message.type !== "cashback-price-assistant:sanitizedItems" || !message.payload) {
      return;
    }

    const payload = message.payload;
    if (!payload.consent || !payload.connectionId || !payload.marketplace) {
      return;
    }

    requestJson("/connections/" + encodeURIComponent(payload.connectionId) + "/immediate-import", {
      method: "POST",
      body: {
        marketplace: payload.marketplace,
        consent: true,
        collection_type: payload.collectionType || "cart",
        captured_at: new Date().toISOString(),
        items: Array.isArray(payload.items) ? payload.items : [],
      },
    }).then(function () {
      setState(payload.marketplace, "sync ok");
    }).catch(function () {
      setState(payload.marketplace, "reconnect_required");
    });
  });

  requestJson("/connections")
    .then(function (data) {
      const connections = Array.isArray(data) ? data : data.connections || [];
      connections.forEach(function (connection) {
        if (connection.marketplace && connection.status) {
          setState(connection.marketplace, connection.status);
        }
      });
    })
    .catch(function () {});
})();
