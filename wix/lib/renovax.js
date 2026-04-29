/**
 * RENOVAX Payments — minimal API client for the Wix connector.
 * Mirrors the auth/error pattern of the WooCommerce, Shopify and Dhru integrations.
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

  async _request(method, path, body) {
    if (!this.token) throw new Error('RENOVAX bearer token is not configured');

    const init = {
      method,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept:        'application/json',
        'User-Agent':  'RenovaxWix/1.0',
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
      const e = new Error('RENOVAX authentication failed');
      e.code = 'renovax_auth'; e.status = res.status; throw e;
    }
    if (res.status === 422) {
      const e = new Error((data && data.message) || 'Unprocessable request');
      e.code = 'renovax_validation'; e.status = res.status; e.data = data; throw e;
    }
    if (!res.ok) {
      const e = new Error(`Unexpected status ${res.status}`);
      e.code = 'renovax_http'; e.status = res.status; throw e;
    }
    return data;
  }
}

module.exports = { RenovaxClient };
