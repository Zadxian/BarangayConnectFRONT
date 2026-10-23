let csrfToken;
let csrfName;

async function readResponse(response) {
  const text = await response.text();
  let result;
  try {
    result = text ? JSON.parse(text) : {};
  } catch {
    if (response.status >= 500) {
      throw new Error('The server could not complete the request. Check that the database migrations have run and review the PHP backend error for details.');
    }
    throw new Error(`The server returned an unexpected response (${response.status}). Check that the PHP backend is running.`);
  }
  if (!response.ok) {
    throw new Error(result.error || `Request failed (${response.status}).`);
  }
  return result.data;
}

async function refreshCsrf() {
  const response = await fetch('/api/csrf', { credentials: 'same-origin' });
  const payload = await readResponse(response);
  csrfToken = payload.token;
  csrfName = payload.name;
}

export async function api(path, { method = 'GET', body } = {}) {
  const upperMethod = method.toUpperCase();
  const headers = {};
  let requestBody = body;
  if (upperMethod !== 'GET' && upperMethod !== 'HEAD') {
    if (!csrfToken || !csrfName) {
      await refreshCsrf();
    }
    if (body instanceof FormData) {
      requestBody.set(csrfName, csrfToken);
    } else {
      requestBody = new URLSearchParams();
      for (const [key, value] of Object.entries(body || {})) {
        if (value !== null && value !== undefined) {
          requestBody.set(key, String(value));
        }
      }
      requestBody.set(csrfName, csrfToken);
      headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
    }
  }
  const response = await fetch(path, {
    method: upperMethod,
    body: requestBody,
    headers,
    credentials: 'same-origin'
  });
  return readResponse(response);
}

export async function submitForm(path, form) {
  return api(path, { method: 'POST', body: new FormData(form) });
}
