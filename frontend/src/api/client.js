/**
 * SocialFlow - Centralized API Client
 * Automatically handles HttpOnly cookie credentials, CSRF token headers, and workspace scoping.
 */

let currentCsrfToken = null;
let currentWorkspaceId = null;

export const setClientCsrf = (token) => {
  currentCsrfToken = token;
};

export const setClientWorkspace = (workspaceId) => {
  currentWorkspaceId = workspaceId;
};

// Helper to get CSRF token from cookie if not set in memory
const getCsrfFromCookie = () => {
  const match = document.cookie.match(/(?:^|;\s*)socialflow_csrf=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : null;
};

export async function apiRequest(endpoint, options = {}) {
  const headers = {
    'Accept': 'application/json',
    ...(options.headers || {}),
  };

  // Only set Content-Type to JSON if body is not FormData
  if (!(options.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  // Attach CSRF Token for state-changing requests
  const method = (options.method || 'GET').toUpperCase();
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    const csrf = currentCsrfToken || getCsrfFromCookie();
    if (csrf) {
      headers['X-CSRF-Token'] = csrf;
    }
  }

  // Attach Active Workspace ID
  if (currentWorkspaceId) {
    headers['X-Workspace-Id'] = String(currentWorkspaceId);
  }

  const response = await fetch(endpoint, {
    ...options,
    headers,
    credentials: 'include', // Ensures HttpOnly cookies are sent and received
  });

  let data = null;
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    data = await response.json();
  } else {
    data = await response.text();
  }

  if (!response.ok) {
    const errorMsg = data?.message || `Request failed with status ${response.status}`;
    const err = new Error(errorMsg);
    err.status = response.status;
    err.errors = data?.errors || null;
    err.payload = data;
    throw err;
  }

  return data;
}
