/**
 * Saleor GraphQL client — only what the RENOVAX App needs.
 *
 * The App calls back into Saleor (after a RENOVAX webhook) to report
 * transaction events via the `transactionEventReport` mutation.
 */

const TRANSACTION_EVENT_REPORT = `
mutation TransactionEventReport(
  $id: ID!,
  $type: TransactionEventTypeEnum!,
  $amount: PositiveDecimal!,
  $pspReference: String!,
  $time: DateTime,
  $externalUrl: String,
  $message: String
) {
  transactionEventReport(
    id: $id,
    type: $type,
    amount: $amount,
    pspReference: $pspReference,
    time: $time,
    externalUrl: $externalUrl,
    message: $message
  ) {
    alreadyProcessed
    errors { field message code }
  }
}
`;

class SaleorClient {
  constructor({ saleorApiUrl, appToken }) {
    this.saleorApiUrl = saleorApiUrl;
    this.appToken     = appToken;
  }

  async transactionEventReport(vars) {
    return this._gql(TRANSACTION_EVENT_REPORT, vars);
  }

  async _gql(query, variables) {
    const res = await fetch(this.saleorApiUrl, {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        Accept:          'application/json',
        Authorization:   `Bearer ${this.appToken}`,
        'User-Agent':    'RenovaxSaleor/1.0',
      },
      body: JSON.stringify({ query, variables }),
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { /* not json */ }

    if (!res.ok) {
      const err = new Error(`Saleor GraphQL HTTP ${res.status}`);
      err.status = res.status;
      err.data   = data;
      throw err;
    }
    if (data && data.errors && data.errors.length) {
      const err = new Error('Saleor GraphQL errors: ' + data.errors.map(e => e.message).join('; '));
      err.data = data;
      throw err;
    }
    return data && data.data;
  }
}

module.exports = { SaleorClient };
