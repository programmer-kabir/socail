import React, { createContext, useContext, useState, useEffect } from 'react';
import { apiRequest, setClientCsrf, setClientWorkspace } from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [workspaces, setWorkspaces] = useState([]);
  const [activeWorkspace, setActiveWorkspace] = useState(null);
  const [csrfToken, setCsrfToken] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchCurrentUser = async (preferredWorkspaceId = null) => {
    try {
      if (preferredWorkspaceId) {
        setClientWorkspace(preferredWorkspaceId);
      }
      const res = await apiRequest('/api/auth/me');
      if (res.success && res.data) {
        setUser(res.data.user);
        setWorkspaces(res.data.workspaces || []);
        setActiveWorkspace(res.data.active_workspace || null);
        if (res.data.csrf_token) {
          setCsrfToken(res.data.csrf_token);
          setClientCsrf(res.data.csrf_token);
        }
        if (res.data.active_workspace?.id) {
          setClientWorkspace(res.data.active_workspace.id);
        }
      } else {
        setUser(null);
      }
    } catch (err) {
      setUser(null);
      setActiveWorkspace(null);
      setWorkspaces([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCurrentUser();
  }, []);

  const login = async (email, password) => {
    setError(null);
    const res = await apiRequest('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });

    if (res.success && res.data) {
      setUser(res.data.user);
      setWorkspaces(res.data.workspaces || []);
      setActiveWorkspace(res.data.active_workspace || null);
      if (res.data.csrf_token) {
        setCsrfToken(res.data.csrf_token);
        setClientCsrf(res.data.csrf_token);
      }
      if (res.data.active_workspace?.id) {
        setClientWorkspace(res.data.active_workspace.id);
      }
      return res.data;
    }
  };

  const register = async (name, email, password) => {
    setError(null);
    const res = await apiRequest('/api/auth/register', {
      method: 'POST',
      body: JSON.stringify({ name, email, password }),
    });

    if (res.success && res.data) {
      setUser(res.data.user);
      const ws = res.data.workspace;
      setWorkspaces(ws ? [ws] : []);
      setActiveWorkspace(ws || null);
      if (res.data.csrf_token) {
        setCsrfToken(res.data.csrf_token);
        setClientCsrf(res.data.csrf_token);
      }
      if (ws?.id) {
        setClientWorkspace(ws.id);
      }
      return res.data;
    }
  };

  const logout = async () => {
    try {
      await apiRequest('/api/auth/logout', { method: 'POST' });
    } catch (e) {
      // Continue cleanup on frontend regardless of server response
    }
    setUser(null);
    setActiveWorkspace(null);
    setWorkspaces([]);
    setCsrfToken(null);
    setClientCsrf(null);
    setClientWorkspace(null);
  };

  const switchWorkspace = async (workspaceId) => {
    setLoading(true);
    setClientWorkspace(workspaceId);
    await fetchCurrentUser(workspaceId);
  };

  const createWorkspace = async (name, timezone = 'UTC') => {
    const res = await apiRequest('/api/workspaces', {
      method: 'POST',
      body: JSON.stringify({ name, timezone }),
    });

    if (res.success && res.data) {
      await fetchCurrentUser(res.data.id);
      return res.data;
    }
  };

  const updateActiveWorkspace = async (name, timezone) => {
    const res = await apiRequest('/api/workspaces/current', {
      method: 'PUT',
      body: JSON.stringify({ name, timezone }),
    });

    if (res.success && res.data) {
      setActiveWorkspace(res.data);
      setWorkspaces((prev) =>
        prev.map((w) => (w.id === res.data.id ? { ...w, ...res.data } : w))
      );
      return res.data;
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        workspaces,
        activeWorkspace,
        csrfToken,
        loading,
        error,
        login,
        register,
        logout,
        switchWorkspace,
        createWorkspace,
        updateActiveWorkspace,
        refreshUser: fetchCurrentUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
