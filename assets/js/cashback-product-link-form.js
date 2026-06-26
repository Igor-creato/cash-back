(function () {
  "use strict";

  const config = window.CashbackProductLinkForm || {};
  const unavailableText = "Кэшбэк не начисляется по этому товару";

  function renderResult(form, data) {
    const result = form.querySelector("[data-cashback-product-link-result]");
    const warning = form.querySelector("[data-cashback-product-link-warning]");
    if (!result || !warning) {
      return;
    }

    const hasCashback = Boolean(data && data.cashback_available);
    const url = data && typeof data.url === "string" ? data.url : "";
    const buttonText =
      data && typeof data.button_text === "string"
        ? data.button_text
        : hasCashback
          ? "Активировать кэшбэк"
          : "Перейти в магазин";

    warning.hidden = hasCashback;
    warning.textContent =
      data && typeof data.warning === "string" ? data.warning : unavailableText;

    const merchant =
      data && typeof data.merchant === "string" && data.merchant !== ""
        ? `<span class="cashback-product-link-form__merchant">${escapeHtml(data.merchant)}</span>`
        : "";
    const rate =
      data && typeof data.cashback_rate === "string" && data.cashback_rate !== ""
        ? `<span class="cashback-product-link-form__rate">${escapeHtml(data.cashback_rate)}</span>`
        : "";
    const button = url
      ? `<a class="cashback-product-link-form__button" href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(buttonText)}</a>`
      : "";

    result.innerHTML = `${merchant}${rate}${button}`;
  }

  function renderError(form) {
    renderResult(form, {
      cashback_available: false,
      button_text: "Перейти в магазин",
      warning: unavailableText,
      url: "",
    });
  }

  async function submitForm(form) {
    const input = form.querySelector('input[name="direct_url"]');
    const submit = form.querySelector('button[type="submit"]');
    const directUrl = input ? input.value.trim() : "";
    if (!directUrl || !config.endpoint) {
      renderError(form);
      return;
    }

    if (submit) {
      submit.disabled = true;
    }

    try {
      const response = await fetch(config.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce || "",
        },
        body: JSON.stringify({
          direct_url: directUrl,
          source: "user",
        }),
      });
      const data = await response.json();
      if (!response.ok) {
        renderError(form);
        return;
      }
      renderResult(form, data);
    } catch (error) {
      renderError(form);
    } finally {
      if (submit) {
        submit.disabled = false;
      }
    }
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[char];
    });
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, "&#096;");
  }

  document.addEventListener("submit", function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-cashback-product-link-form]")) {
      return;
    }
    event.preventDefault();
    submitForm(form);
  });
})();
