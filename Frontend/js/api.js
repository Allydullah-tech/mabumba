/**
 * MABUMBATECH — API client
 * Thin fetch wrapper around Backend/. Every page includes this first.
 * All backend endpoints reply with { success, message?, data? } — this
 * normalises that into either a resolved value or a thrown Error whose
 * .message is safe to show directly to the user.
 */
// Each page sets window.API_BASE (relative to that page's own folder)
// before loading this script, e.g. 'Backend' for pages at the Frontend
// root, or '../Backend' for pages under Frontend/pages/. This is what
// lets the whole app work when it's deployed in a subfolder (e.g.
// htdocs/mabumbatech-business/) instead of the web server's root.
const API_BASE = window.API_BASE || 'Backend';

async function apiCall(path, { method = 'GET', body = null, query = null } = {}) {
  let url = `${API_BASE}/${path}`;
  if (query) {
    const qs = new URLSearchParams(
      Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== '')
    ).toString();
    if (qs) url += `?${qs}`;
  }

  const opts = {
    method,
    credentials: 'include',
    headers: {},
  };
  if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }

  let res, json;
  try {
    res = await fetch(url, opts);
  } catch (e) {
    throw new Error('Could not reach the server. Check your connection and try again.');
  }

  try {
    json = await res.json();
  } catch (e) {
    throw new Error('The server sent back an unexpected response (HTTP ' + res.status + ').');
  }

  if (!json.success) {
    if (res.status === 401 && !path.startsWith('auth/')) {
      // Session expired mid-use — bounce to login rather than showing a
      // dead page full of failed requests.
      window.location.href = (window.FRONTEND_BASE || '') + 'login.html';
    }
    throw new Error(json.message || 'Something went wrong. Please try again.');
  }
  return json.data;
}

const api = {
  get: (path, query) => apiCall(path, { method: 'GET', query }),
  post: (path, body) => apiCall(path, { method: 'POST', body }),
  put: (path, body) => apiCall(path, { method: 'PUT', body }),
  del: (path, query) => apiCall(path, { method: 'DELETE', query }),
};
