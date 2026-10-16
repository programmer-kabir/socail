/**
 * SocialFlow - Centralized API Client
 * Automatically handles HttpOnly cookie credentials, CSRF token headers, and workspace scoping.
 */

let currentCsrfToken = null;
let currentWorkspaceId = localStorage.getItem('socialflow_workspace_id') || null;

export const setClientCsrf = (token) => {
  currentCsrfToken = token;
};

export const setClientWorkspace = (workspaceId) => {
  currentWorkspaceId = workspaceId;
  if (workspaceId) {
    localStorage.setItem('socialflow_workspace_id', String(workspaceId));
  } else {
    localStorage.removeItem('socialflow_workspace_id');
  }
};

// Token management for reliable cross-origin and cookie-less auth
export const getClientToken = () => localStorage.getItem('socialflow_token');
export const setClientToken = (token) => {
  if (token) {
    localStorage.setItem('socialflow_token', token);
  } else {
    localStorage.removeItem('socialflow_token');
  }
};

// Environment-configurable API Base URL
export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/+$/, '');

/**
 * Intelligent endpoint resolver:
 * Maps abstract /api/... routes directly to self-contained /api_v1/... modular PHP files
 */
export const getApiUrl = (endpoint = '', options = {}) => {
  if (!endpoint) return '';
  if (endpoint.startsWith('http://') || endpoint.startsWith('https://')) {
    return endpoint;
  }

  let clean = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
  const method = (options.method || 'GET').toUpperCase();

  // If already targeting api_v1, return with base
  if (clean.startsWith('/api_v1/')) {
    return API_BASE_URL ? `${API_BASE_URL}${clean}` : clean;
  }

  // Map /api/... to modular /api_v1/... files
  let mapped = clean;

  // 1. Auth routes
  if (clean.startsWith('/api/auth/login')) mapped = '/api_v1/auth/login.php';
  else if (clean.startsWith('/api/auth/register')) mapped = '/api_v1/auth/register.php';
  else if (clean.startsWith('/api/auth/me')) mapped = '/api_v1/auth/me.php';
  else if (clean.startsWith('/api/auth/logout')) mapped = '/api_v1/auth/logout.php';
  else if (clean.startsWith('/api/auth/forgot-password')) mapped = '/api_v1/auth/forgot_password.php';
  else if (clean.startsWith('/api/auth/reset-password')) mapped = '/api_v1/auth/reset_password.php';

  // 2. Dashboard routes
  else if (clean.startsWith('/api/dashboard/stats')) mapped = '/api_v1/dashboard/stats.php';
  else if (clean.startsWith('/api/dashboard/activity')) mapped = '/api_v1/dashboard/activity.php';

  // 3. Accounts routes
  else if (clean.startsWith('/api/accounts/sandbox/connect')) mapped = '/api_v1/accounts/sandbox.php';
  else if (clean.startsWith('/api/accounts/oauth/url')) mapped = clean.replace('/api/accounts/oauth/url', '/api_v1/accounts/oauth.php');
  else if (clean.startsWith('/api/accounts/oauth/callback')) mapped = '/api_v1/accounts/oauth.php';
  else if (clean.match(/^\/api\/accounts\/(\d+)\/disconnect/)) {
    const id = clean.match(/^\/api\/accounts\/(\d+)\/disconnect/)[1];
    mapped = `/api_v1/accounts/disconnect.php?id=${id}`;
  } else if (clean === '/api/accounts' || clean.startsWith('/api/accounts?')) {
    mapped = clean.replace('/api/accounts', '/api_v1/accounts/index.php');
  }

  // 4. Posts routes
  else if (clean.match(/^\/api\/posts\/(\d+)\/publish/)) {
    const id = clean.match(/^\/api\/posts\/(\d+)\/publish/)[1];
    mapped = `/api_v1/posts/publish.php?id=${id}`;
  } else if (clean.match(/^\/api\/posts\/(\d+)\/cancel-schedule/)) {
    const id = clean.match(/^\/api\/posts\/(\d+)\/cancel-schedule/)[1];
    mapped = `/api_v1/posts/cancel_schedule.php?id=${id}`;
  } else if (clean.match(/^\/api\/posts\/(\d+)/)) {
    const id = clean.match(/^\/api\/posts\/(\d+)/)[1];
    mapped = `/api_v1/posts/index.php?id=${id}`;
  } else if (clean === '/api/posts' && method === 'POST') {
    mapped = '/api_v1/posts/create.php';
  } else if (clean.startsWith('/api/posts')) {
    mapped = clean.replace('/api/posts', '/api_v1/posts/index.php');
  }

  // 5. Calendar routes
  else if (clean.startsWith('/api/calendar')) {
    mapped = clean.replace('/api/calendar', '/api_v1/calendar/index.php');
  }

  // 6. Workspaces routes
  else if (clean.startsWith('/api/workspaces/current')) {
    mapped = '/api_v1/workspaces/current.php';
  } else if (clean.startsWith('/api/workspaces')) {
    mapped = '/api_v1/workspaces/index.php';
  }

  // 7. Media routes
  else if (clean.startsWith('/api/media/upload')) {
    mapped = '/api_v1/media/upload.php';
  } else if (clean.match(/^\/api\/media\/file\/([^?]+)/)) {
    const fname = clean.match(/^\/api\/media\/file\/([^?]+)/)[1];
    mapped = `/api_v1/media/file.php?filename=${fname}`;
  }

  // 8. Health check
  else if (clean.startsWith('/api/health')) {
    mapped = '/api_v1/health.php';
  }

  return API_BASE_URL ? `${API_BASE_URL}${mapped}` : mapped;
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

  // Attach Bearer token if available
  const token = getClientToken();
  if (token && !headers['Authorization']) {
    headers['Authorization'] = `Bearer ${token}`;
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

  const url = getApiUrl(endpoint, options);
  const response = await fetch(url, {
    ...options,
    headers,
    credentials: 'include',
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
