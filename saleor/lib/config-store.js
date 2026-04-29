/**
 * Per-tenant RENOVAX configuration store.
 *
 * Each Saleor tenant configures its own RENOVAX bearer + webhook secret
 * via the Dashboard extension. We persist them next to the APL using
 * the same backend (file or Redis) but a different key namespace.
 */

const fs   = require('node:fs');
const path = require('node:path');

class FileConfigStore {
  constructor({ filePath }) {
    this.filePath = filePath || path.join(process.cwd(), '.renovax-config.json');
    if (!fs.existsSync(this.filePath)) fs.writeFileSync(this.filePath, '{}');
  }
  _read()    { try { return JSON.parse(fs.readFileSync(this.filePath, 'utf8') || '{}'); } catch { return {}; } }
  _write(o)  { fs.writeFileSync(this.filePath, JSON.stringify(o, null, 2)); }

  async get(saleorApiUrl)         { return this._read()[saleorApiUrl] || null; }
  async set(saleorApiUrl, cfg)    { const all = this._read(); all[saleorApiUrl] = cfg; this._write(all); }
  async delete(saleorApiUrl)      { const all = this._read(); delete all[saleorApiUrl]; this._write(all); }
  async findByMerchantToken(token) {
    const all = this._read();
    for (const [url, cfg] of Object.entries(all)) {
      if (cfg.renovaxIngressToken === token) return { saleorApiUrl: url, cfg };
    }
    return null;
  }
}

class RedisConfigStore {
  constructor({ redis, keyPrefix = 'saleor:cfg:', indexPrefix = 'saleor:cfg-by-token:' }) {
    this.redis       = redis;
    this.keyPrefix   = keyPrefix;
    this.indexPrefix = indexPrefix;
  }
  _key(url)        { return this.keyPrefix + url; }
  _idx(token)      { return this.indexPrefix + token; }

  async get(saleorApiUrl) {
    const v = await this.redis.get(this._key(saleorApiUrl));
    return v ? JSON.parse(v) : null;
  }
  async set(saleorApiUrl, cfg) {
    const prev = await this.get(saleorApiUrl);
    if (prev && prev.renovaxIngressToken && prev.renovaxIngressToken !== cfg.renovaxIngressToken) {
      await this.redis.del(this._idx(prev.renovaxIngressToken));
    }
    await this.redis.set(this._key(saleorApiUrl), JSON.stringify(cfg));
    if (cfg.renovaxIngressToken) {
      await this.redis.set(this._idx(cfg.renovaxIngressToken), saleorApiUrl);
    }
  }
  async delete(saleorApiUrl) {
    const prev = await this.get(saleorApiUrl);
    await this.redis.del(this._key(saleorApiUrl));
    if (prev && prev.renovaxIngressToken) await this.redis.del(this._idx(prev.renovaxIngressToken));
  }
  async findByMerchantToken(token) {
    const url = await this.redis.get(this._idx(token));
    if (!url) return null;
    const cfg = await this.get(url);
    return cfg ? { saleorApiUrl: url, cfg } : null;
  }
}

function makeConfigStore({ redis } = {}) {
  if ((process.env.APL || 'file').toLowerCase() === 'redis') {
    if (!redis) {
      const Redis = require('ioredis');
      redis = new Redis(process.env.REDIS_URL);
    }
    return new RedisConfigStore({ redis });
  }
  return new FileConfigStore({ filePath: process.env.CONFIG_FILE });
}

module.exports = { FileConfigStore, RedisConfigStore, makeConfigStore };
