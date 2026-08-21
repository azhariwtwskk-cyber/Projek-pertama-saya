import { APP_CONFIG, DEMO_ACCOUNTS, DEMO_DASHBOARDS, DEMO_PROPERTY } from './config.js?v=4110';

export class ApiError extends Error {
  constructor(message, code = 'API_ERROR', status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

export function normalizeBaseUrl(value) {
  return String(value || '').trim().replace(/\/+$/, '');
}

export function isSupportedRole(role) {
  return APP_CONFIG.supportedRoles.includes(String(role || '').toLowerCase());
}

export function routeForRole(role) {
  if (!isSupportedRole(role)) {
    throw new ApiError('Peranan ini tidak dibenarkan menggunakan CPMS Workforce.', 'ROLE_NOT_ALLOWED', 403);
  }
  return role === 'security' ? 'security' : 'staff';
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function demoDelay(milliseconds = 250) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function xhrResponse(url, options = {}, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    if (typeof XMLHttpRequest === 'undefined') {
      reject(new Error('XMLHttpRequest tidak tersedia'));
      return;
    }

    const xhr = new XMLHttpRequest();
    xhr.open(options.method || 'GET', url, true);
    xhr.timeout = timeoutMs;
    Object.entries(options.headers || {}).forEach(([name, value]) => {
      xhr.setRequestHeader(name, value);
    });
    xhr.onload = () => {
      const responseText = String(xhr.responseText || '');
      resolve({
        ok: xhr.status >= 200 && xhr.status < 300,
        status: xhr.status,
        json: async () => {
          try { return JSON.parse(responseText); }
          catch { return null; }
        },
      });
    };
    xhr.onerror = () => reject(new Error(`XHR network error (status ${xhr.status || 0})`));
    xhr.ontimeout = () => {
      const error = new Error('XHR timeout');
      error.name = 'AbortError';
      reject(error);
    };
    xhr.send(options.body === undefined ? null : options.body);
  });
}

async function fetchWithXhrFallback(fetchImpl, url, options, timeoutMs) {
  let fetchError = null;
  try {
    return await fetchImpl(url, options);
  } catch (error) {
    fetchError = error;
  }

  try {
    return await xhrResponse(url, options, timeoutMs);
  } catch (xhrError) {
    const fetchMessage = fetchError?.message || String(fetchError || 'unknown');
    const xhrMessage = xhrError?.message || String(xhrError || 'unknown');
    throw new ApiError(
      `Sambungan gagal. Fetch: ${fetchMessage}; XHR: ${xhrMessage}`,
      'NETWORK_TRANSPORT_FAILED',
      0
    );
  }
}

export class ApiClient {
  constructor(config = APP_CONFIG, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.fetchImpl = fetchImpl;
    this.accessToken = '';
  }

  setAccessToken(token) {
    this.accessToken = String(token || '');
  }

  async login(username, password) {
    const cleanUsername = String(username || '').trim().toLowerCase();
    const cleanPassword = String(password || '');

    if (this.config.demoMode) {
      await demoDelay();
      const account = DEMO_ACCOUNTS[cleanUsername];
      if (!account || account.password !== cleanPassword) {
        throw new ApiError('Username atau kata laluan tidak sah.', 'INVALID_CREDENTIALS', 401);
      }
      const token = `demo-${account.role}-${Date.now()}`;
      this.setAccessToken(token);
      return {
        access_token: token,
        expires_in: 3600,
        user: { ...clone(account.user), role: account.role },
        property: clone(DEMO_PROPERTY),
      };
    }

    const data = await this.request('/auth/login.php', {
      method: 'POST',
      auth: false,
      body: {
        username: cleanUsername,
        password: cleanPassword,
        device: { platform: 'android', app_version: this.config.version },
      },
    });
    routeForRole(data.user?.role);
    this.setAccessToken(data.access_token);
    return data;
  }

  async dashboard(role) {
    const cleanRole = routeForRole(role);
    if (this.config.demoMode) {
      await demoDelay(120);
      return clone(DEMO_DASHBOARDS[cleanRole]);
    }
    return this.request('/dashboard.php');
  }

  async createWebSession() {
    if (this.config.demoMode) {
      return { redirect_url: '', demo: true };
    }
    return this.request('/auth/web-session.php', { method: 'POST', body: {} });
  }

  async notifications() {
    if (this.config.demoMode) {
      await demoDelay(80);
      return { notifications: [] };
    }
    return this.request('/notifications.php');
  }

  async submitAction(path, body) {
    if (this.config.demoMode) {
      await demoDelay(160);
      return { accepted: true, demo: true, path, received_at: new Date().toISOString(), payload: clone(body) };
    }
    return this.request(path, { method: 'POST', body });
  }

  async upload(path, formData) {
    if (this.config.demoMode) {
      await demoDelay(160);
      return { accepted: true, demo: true, path };
    }
    if (typeof this.fetchImpl !== 'function') {
      throw new ApiError('Network API tidak tersedia.', 'NETWORK_UNAVAILABLE');
    }
    if (!this.accessToken) {
      throw new ApiError('Sesi login tidak dijumpai.', 'AUTH_REQUIRED', 401);
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.config.requestTimeoutMs * 2);
    try {
      const response = await fetchWithXhrFallback(this.fetchImpl, `${normalizeBaseUrl(this.config.apiBaseUrl)}${path}`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${this.accessToken}`,
          'X-CPMS-Authorization': `Bearer ${this.accessToken}`,
        },
        body: formData,
        signal: controller.signal,
        cache: 'no-store',
      }, this.config.requestTimeoutMs * 2);
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        const error = payload?.error || {};
        throw new ApiError(error.message || 'Muat naik gagal.', error.code || 'UPLOAD_FAILED', response.status);
      }
      return payload.data;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      if (error?.name === 'AbortError') {
        throw new ApiError('Muat naik mengambil masa terlalu lama.', 'REQUEST_TIMEOUT');
      }
      throw new ApiError('Tidak dapat menyambung ke server CPMS.', 'NETWORK_ERROR');
    } finally {
      clearTimeout(timeoutId);
    }
  }

  async uploadTaskPhoto(workOrderReference, file, imageType = 'Supporting') {
    const formData = new FormData();
    formData.append('work_order_reference', workOrderReference);
    formData.append('image_type', imageType);
    formData.append('photo', file, file.name || 'task-photo.jpg');
    return this.upload('/staff/task-photo.php', formData);
  }

  async submitIncident(payload, file = null) {
    if (!file) return this.submitAction('/security/incidents.php', payload);
    const formData = new FormData();
    formData.append('description', payload.description || '');
    formData.append('location_name', payload.location_name || '');
    formData.append('priority', payload.priority || 'Medium');
    formData.append('location', JSON.stringify(payload.location || {}));
    formData.append('recorded_at', payload.recorded_at || new Date().toISOString());
    formData.append('photo', file, file.name || 'incident-photo.jpg');
    return this.upload('/security/incidents.php', formData);
  }

  async logout() {
    try {
      if (!this.config.demoMode && this.accessToken) {
        await this.request('/auth/logout.php', { method: 'POST' });
      }
    } finally {
      this.setAccessToken('');
    }
  }

  async request(path, options = {}) {
    if (typeof this.fetchImpl !== 'function') {
      throw new ApiError('Network API tidak tersedia.', 'NETWORK_UNAVAILABLE');
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.config.requestTimeoutMs);
    const method = options.method || 'GET';
    const auth = options.auth !== false;
    const headers = { Accept: 'application/json' };
    let requestBody;

    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      const formBody = new URLSearchParams();
      formBody.set('payload_json', JSON.stringify(options.body));
      requestBody = formBody.toString();
    }
    if (auth) {
      if (!this.accessToken) {
        clearTimeout(timeoutId);
        throw new ApiError('Sesi login tidak dijumpai.', 'AUTH_REQUIRED', 401);
      }
      headers.Authorization = `Bearer ${this.accessToken}`;
      headers['X-CPMS-Authorization'] = `Bearer ${this.accessToken}`;
    }

    try {
      const response = await fetchWithXhrFallback(this.fetchImpl, `${normalizeBaseUrl(this.config.apiBaseUrl)}${path}`, {
        method,
        headers,
        body: requestBody,
        signal: controller.signal,
        cache: 'no-store',
      }, this.config.requestTimeoutMs);
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        const error = payload?.error || {};
        throw new ApiError(error.message || 'Permintaan CPMS gagal.', error.code || 'REQUEST_FAILED', response.status);
      }
      return payload.data;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      if (error?.name === 'AbortError') {
        throw new ApiError('Sambungan mengambil masa terlalu lama.', 'REQUEST_TIMEOUT');
      }
      throw new ApiError('Tidak dapat menyambung ke server CPMS.', 'NETWORK_ERROR');
    } finally {
      clearTimeout(timeoutId);
    }
  }
}

export class OfflineQueue {
  constructor(storage = globalThis.localStorage, key = 'cpms_workforce_queue_v1') {
    this.storage = storage;
    this.key = key;
  }

  list() {
    try {
      const parsed = JSON.parse(this.storage?.getItem(this.key) || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  add(path, body) {
    const items = this.list();
    items.push({ id: `${Date.now()}-${Math.random().toString(16).slice(2)}`, path, body, queued_at: new Date().toISOString() });
    this.storage?.setItem(this.key, JSON.stringify(items));
    return items.length;
  }

  clear() {
    this.storage?.removeItem(this.key);
  }

  async flush(apiClient) {
    const pending = this.list();
    const failed = [];
    for (const item of pending) {
      try {
        await apiClient.submitAction(item.path, item.body);
      } catch {
        failed.push(item);
      }
    }
    if (failed.length) this.storage?.setItem(this.key, JSON.stringify(failed));
    else this.clear();
    return { sent: pending.length - failed.length, failed: failed.length };
  }
}
