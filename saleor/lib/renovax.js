/**
 * RENOVAX Payments — minimal API client for the Saleor App.
 * Mirrors the auth/error pattern of the Shopify and WooCommerce integrations.
 */

class RenovaxClient {
  constructor({ apiBase, token }) {
    this.apiBase = (apiBase || 'https://payments.renovax.net').replace(/\/+$/, '');
    this.token   = (token || '').trim();
  }

  async createInvoice(payload) {
    return this._request('POST', '/api/v1/merchant/invoices', payload);
  }

  async getInvoice(id) {
    return this._request('GET', `/api/v1/merchant/invoices/${encodeURIComponent(id)}`);
  }

  async refundInvoice(id, { amount, reason } = {}) {
    const body = {};
    if (amount !== undefined) body.amount = String(amount);
    if (reason)               body.reason = String(reason);
    return this._request('POST', `/api/v1/merchant/invoices/${encodeURIComponent(id)}/refund`, body);
  }

  async cancelInvoice(id) {
    return this._request('POST', `/api/v1/merchant/invoices/${encodeURIComponent(id)}/cancel`);
  }

  async verifyToken() {
    return this._request('GET', '/api/v1/merchant/me');
  }

  async _request(method, path, body) {
    if (!this.token) throw new RenovaxError('renovax_auth', 'RENOVAX bearer token is not configured', 0, null);

    const init = {
      method,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept:        'application/json',
        'User-Agent':  'RenovaxSaleor/1.0',
      },
    };
    if (body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }

    const res  = await fetch(this.apiBase + path, init);
    const text = await res.text();
    let   data = null;
    try { data = text ? JSON.parse(text) : null; } catch { /* not json */ }

    if (res.status === 401 || res.status === 403) {
      throw new RenovaxError('renovax_auth', 'Authentication failed — verify RENOVAX bearer token', res.status, data);
    }
    if (res.status === 422) {
      throw new RenovaxError('renovax_validation', (data && data.message) || 'Unprocessable request', res.status, data);
    }
    if (!res.ok) {
      throw new RenovaxError('renovax_http', `Unexpected status ${res.status}`, res.status, data);
    }
    return data;
  }
}

class RenovaxError extends Error {
  constructor(code, message, status, data) {
    super(message);
    this.code   = code;
    this.status = status;
    this.data   = data;
  }
}

module.exports = { RenovaxClient, RenovaxError };
