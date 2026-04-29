/**
 * APL — App Persistence Layer.
 *
 * Stores the per-tenant Saleor `auth_token` keyed by `saleor_api_url`,
 * so a single deployment can serve N Saleor stores.
 *
 * Two implementations:
 *   - FileAPL  (default in dev)  — JSON file on disk.
 *   - RedisAPL (prod, optional)  — uses the project Redis with prefix `saleor:apl:`.
 *
 * Aligned with the project Redis design: 1 single DB, hierarchical
 * prefixes, no new connections — share the same Redis instance.
 */

const fs   = require('node:fs');
const path = require('node:path');

class FileAPL {
  constructor({ filePath }) {
    this.filePath = filePath || path.join(process.cwd(), '.apl.json');
    if (!fs.existsSync(this.filePath)) fs.writeFileSync(this.filePath, '{}');
  }
  _read()  { try { return JSON.parse(fs.readFileSync(this.filePath, 'utf8') || '{}'); } catch { return {}; } }
  _write(o){ fs.writeFileSync(this.filePath, JSON.stringify(o, null, 2)); }

  async get(saleorApiUrl)        { return this._read()[saleorApiUrl] || null; }
  async set(authData)            { const all = this._read(); all[authData.saleorApiUrl] = authData; this._write(all); }
  async delete(saleorApiUrl)     { const all = this._read(); delete all[saleorApiUrl]; this._write(all); }
  async getAll()                 { return Object.values(this._read()); }
  async isReady()                { return { ready: true }; }
  async isConfigured()           { return { configured: true }; }
}

class RedisAPL {
  constructor({ redis, keyPrefix = 'saleor:apl:' }) {
    this.redis     = redis;
    this.keyPrefix = keyPrefix;
  }
  _key(saleorApiUrl) { return this.keyPrefix + saleorApiUrl; }

  async get(saleorApiUrl) {
    const v = await this.redis.get(this._key(saleorApiUrl));
    return v ? JSON.parse(v) : null;
  }
  async set(authData) {
    await this.redis.set(this._key(authData.saleorApiUrl), JSON.stringify(authData));
  }
  async delete(saleorApiUrl) {
    await this.redis.del(this._key(saleorApiUrl));
  }
  async getAll() {
    const keys = await this.redis.keys(this.keyPrefix + '*');
    if (!keys.length) return [];
    const vals = await Promise.all(keys.map(k => this.redis.get(k)));
    return vals.filter(Boolean).map(v => JSON.parse(v));
  }
  async isReady()      { return { ready: true }; }
  async isConfigured() { return { configured: true }; }
}

function makeAPL() {
  const driver = (process.env.APL || 'file').toLowerCase();
  if (driver === 'redis') {
    if (!process.env.REDIS_URL) throw new Error('APL=redis requires REDIS_URL');
    const Redis = require('ioredis');
    const redis = new Redis(process.env.REDIS_URL, { keyPrefix: '' });
    return new RedisAPL({ redis });
  }
  return new FileAPL({ filePath: process.env.APL_FILE });
}

module.exports = { FileAPL, RedisAPL, makeAPL };
