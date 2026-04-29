/**
 * Shopify Admin API helper — only the calls this connector needs.
 * Uses GraphQL for marking orders as paid (via orderMarkAsPaid mutation).
 */

class ShopifyClient {
  constructor({ shop, token, apiVersion = '2024-10' }) {
    this.shop       = shop;
    this.token      = token;
    this.apiVersion = apiVersion;
    this.gqlUrl     = `https://${shop}/admin/api/${apiVersion}/graphql.json`;
  }

  async _gql(query, variables) {
    const res = await fetch(this.gqlUrl, {
      method: 'POST',
      headers: {
        'Content-Type':           'application/json',
        'X-Shopify-Access-Token': this.token,
        Accept:                   'application/json',
      },
      body: JSON.stringify({ query, variables }),
    });
    const data = await res.json();
    if (!res.ok || data.errors) {
      throw new Error('Shopify GraphQL error: ' + JSON.stringify(data.errors || data));
    }
    return data.data;
  }

  async getOrder(orderGid) {
    const data = await this._gql(
      `query($id: ID!) {
        order(id: $id) {
          id name email currencyCode
          totalOutstandingSet { shopMoney { amount currencyCode } }
          customer { email }
          financialStatus
        }
      }`,
      { id: orderGid }
    );
    return data.order;
  }

  async markOrderAsPaid(orderGid) {
    const data = await this._gql(
      `mutation($input: OrderMarkAsPaidInput!) {
        orderMarkAsPaid(input: $input) {
          order { id financialStatus }
          userErrors { field message }
        }
      }`,
      { input: { id: orderGid } }
    );
    if (data.orderMarkAsPaid.userErrors?.length) {
      throw new Error('orderMarkAsPaid: ' + JSON.stringify(data.orderMarkAsPaid.userErrors));
    }
    return data.orderMarkAsPaid.order;
  }

  async cancelOrder(orderGid, reason = 'CUSTOMER') {
    const data = await this._gql(
      `mutation($id: ID!, $reason: OrderCancelReason!) {
        orderCancel(orderId: $id, reason: $reason, refund: false, restock: true, notifyCustomer: false) {
          job { id }
          userErrors { field message }
        }
      }`,
      { id: orderGid, reason }
    );
    if (data.orderCancel.userErrors?.length) {
      throw new Error('orderCancel: ' + JSON.stringify(data.orderCancel.userErrors));
    }
    return true;
  }

  async refundOrder(orderGid, amount, currencyCode, note = '') {
    const data = await this._gql(
      `mutation($input: RefundInput!) {
        refundCreate(input: $input) {
          refund { id }
          userErrors { field message }
        }
      }`,
      { input: { orderId: orderGid, note, transactions: [{
          orderId: orderGid,
          gateway: 'manual',
          kind:    'REFUND',
          amount:  String(amount),
          parentId: null,
      }] } }
    );
    if (data.refundCreate.userErrors?.length) {
      throw new Error('refundCreate: ' + JSON.stringify(data.refundCreate.userErrors));
    }
    return data.refundCreate.refund;
  }

  async addOrderNote(orderGid, message) {
    await this._gql(
      `mutation($input: OrderInput!) {
        orderUpdate(input: $input) {
          order { id }
          userErrors { field message }
        }
      }`,
      { input: { id: orderGid, note: message } }
    );
  }
}

module.exports = { ShopifyClient };
