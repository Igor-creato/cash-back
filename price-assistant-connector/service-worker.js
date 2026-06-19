const CONNECTOR_VERSION = "mv3-0.1.0";

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  void sender;

  if (!message || message.action !== "cashbackPriceAssistant.captureSession") {
    return false;
  }

  captureSession(message)
    .then((result) => sendResponse(result))
    .catch((error) => {
      sendResponse({
        ok: false,
        code: error && error.message ? error.message : "connector_failed",
      });
    });

  return true;
});

async function captureSession(message) {
  if (!message.consent) {
    throw new Error("consent_required");
  }

  const allowedNames = allowedCookieNames(message.allowlist);
  if (allowedNames.size === 0) {
    throw new Error("allowlist_empty");
  }

  const origins = Array.isArray(message.hostPermissions) ? message.hostPermissions : [];
  if (origins.length === 0) {
    throw new Error("host_permission_required");
  }

  await requestHostPermissions(origins);

  const cookies = [];
  for (const url of marketplaceCookieUrls(message.pageUrls)) {
    const found = await chrome.cookies.getAll({ url });
    cookies.push(...filterAllowlistedCookies(found, allowedNames));
  }

  const bundle = {
    version: "session-bundle-v1",
    marketplace: String(message.marketplace || ""),
    captured_at: new Date().toISOString(),
    cookies,
    tokens: [],
  };

  return uploadSessionBundle(message, bundle);
}

function allowedCookieNames(allowlist) {
  const names = allowlist && Array.isArray(allowlist.cookies) ? allowlist.cookies : [];
  return new Set(
    names
      .filter((name) => typeof name === "string")
      .map((name) => name.trim())
      .filter((name) => name !== "")
  );
}

function marketplaceCookieUrls(pageUrls) {
  if (!pageUrls || typeof pageUrls !== "object") {
    return [];
  }
  return [pageUrls.login, pageUrls.cart, pageUrls.favorites].filter(
    (url) => typeof url === "string" && /^https:\/\/[^/]+/.test(url)
  );
}

function filterAllowlistedCookies(cookies, allowedNames) {
  return cookies
    .filter((cookie) => allowedNames.has(cookie.name))
    .map((cookie) => ({
      name: cookie.name,
      value: cookie.value,
      domain: cookie.domain,
      path: cookie.path,
      secure: Boolean(cookie.secure),
      httpOnly: Boolean(cookie.httpOnly),
      sameSite: cookie.sameSite || "unspecified",
      expirationDate: cookie.expirationDate || null,
    }));
}

async function requestHostPermissions(origins) {
  const granted = await chrome.permissions.request({ origins });
  if (!granted) {
    throw new Error("host_permission_denied");
  }
}

async function uploadSessionBundle(message, bundle) {
  const connectionId = Number(message.connectionId || 0);
  const restBase = String(message.restBase || "").replace(/\/+$/, "");
  const nonce = String(message.nonce || "");

  if (connectionId <= 0 || restBase === "" || nonce === "") {
    throw new Error("connector_config_invalid");
  }

  const response = await fetch(
    restBase + "/connections/" + encodeURIComponent(String(connectionId)) + "/session-bundle",
    {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": nonce,
      },
      body: JSON.stringify({
        marketplace: message.marketplace,
        consent: true,
        consent_version: message.consentVersion,
        scope: Array.isArray(message.scope) ? message.scope : ["cart_read", "favorites_read"],
        captured_at: bundle.captured_at,
        connector_version: CONNECTOR_VERSION,
        session_bundle: bundle,
      }),
    }
  );

  if (!response.ok) {
    throw new Error(response.status === 401 || response.status === 403 ? "reconnect_required" : "upload_failed");
  }

  return { ok: true, uploaded: true };
}
