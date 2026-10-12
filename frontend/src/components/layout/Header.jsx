import React, { useState, useRef, useEffect } from 'react';
import { useAuth } from '../../context/AuthContext';
import { 
  Share2, 
  ChevronDown, 
  Plus, 
  Building2, 
  Check, 
  LogOut, 
  Settings, 
  X,
  Loader2
} from 'lucide-react';

export default function Header({ currentTab, onSelectTab }) {
  const { user, workspaces, activeWorkspace, switchWorkspace, createWorkspace, logout } = useAuth();
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [showCreateWsModal, setShowCreateWsModal] = useState(false);
  const [newWsName, setNewWsName] = useState('');
  const [newWsTimezone, setNewWsTimezone] = useState('UTC');
  const [creatingWs, setCreatingWs] = useState(false);
  const [createWsError, setCreateWsError] = useState(null);

  const wsRef = useRef(null);
  const userRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (wsRef.current && !wsRef.current.contains(e.target)) {
        setWorkspaceMenuOpen(false);
      }
      if (userRef.current && !userRef.current.contains(e.target)) {
        setUserMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleCreateWorkspace = async (e) => {
    e.preventDefault();
    setCreateWsError(null);
    setCreatingWs(true);
    try {
      await createWorkspace(newWsName, newWsTimezone);
      setShowCreateWsModal(false);
      setNewWsName('');
      setWorkspaceMenuOpen(false);
    } catch (err) {
      setCreateWsError(err.message || 'Failed to create workspace.');
    } finally {
      setCreatingWs(false);
    }
  };

  const navItems = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'accounts', label: 'Social Accounts' },
    { id: 'composer', label: 'Post Composer' },
    { id: 'calendar', label: 'Content Calendar' },
    { id: 'posts', label: 'All Posts' },
    { id: 'settings', label: 'Workspace Settings' },
  ];

  return (
    <>
      <header className="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-40">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          {/* Left: Brand & Workspace Switcher */}
          <div className="flex items-center gap-6">
            <div className="flex items-center gap-2.5">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center shadow-lg shadow-brand-500/20">
                <Share2 className="w-5 h-5 text-white" />
              </div>
              <span className="text-lg font-bold tracking-tight text-white hidden sm:inline">SocialFlow</span>
            </div>

            {/* Workspace Switcher */}
            <div className="relative" ref={wsRef}>
              <button
                type="button"
                onClick={() => setWorkspaceMenuOpen(!workspaceMenuOpen)}
                className="flex items-center gap-2.5 py-1.5 px-3 rounded-xl bg-slate-800/80 hover:bg-slate-800 border border-slate-700/70 text-slate-200 text-xs font-medium transition"
              >
                <Building2 className="w-3.5 h-3.5 text-brand-400" />
                <span className="max-w-[120px] sm:max-w-[160px] truncate">
                  {activeWorkspace ? activeWorkspace.name : 'Select Workspace'}
                </span>
                <span className="capitalize px-1.5 py-0.5 rounded text-[10px] bg-slate-900 border border-slate-700 text-slate-400">
                  {activeWorkspace?.role || 'member'}
                </span>
                <ChevronDown className="w-3.5 h-3.5 text-slate-400 ml-0.5" />
              </button>

              {/* Dropdown Menu */}
              {workspaceMenuOpen && (
                <div className="absolute left-0 mt-2 w-64 rounded-xl bg-slate-900 border border-slate-800 shadow-2xl p-2 z-50 animate-in fade-in zoom-in-95 duration-100">
                  <div className="px-2 py-1.5 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                    Workspaces
                  </div>
                  <div className="space-y-1 max-h-56 overflow-y-auto">
                    {workspaces.map((ws) => (
                      <button
                        key={ws.id}
                        type="button"
                        onClick={() => {
                          switchWorkspace(ws.id);
                          setWorkspaceMenuOpen(false);
                        }}
                        className={`w-full flex items-center justify-between p-2 rounded-lg text-xs transition ${
                          activeWorkspace?.id === ws.id
                            ? 'bg-brand-600/15 text-brand-300 font-semibold border border-brand-500/30'
                            : 'text-slate-300 hover:bg-slate-800'
                        }`}
                      >
                        <div className="flex items-center gap-2 truncate">
                          <Building2 className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                          <span className="truncate">{ws.name}</span>
                        </div>
                        {activeWorkspace?.id === ws.id && (
                          <Check className="w-3.5 h-3.5 text-brand-400 shrink-0" />
                        )}
                      </button>
                    ))}
                  </div>

                  <div className="pt-2 mt-2 border-t border-slate-800">
                    <button
                      type="button"
                      onClick={() => {
                        setWorkspaceMenuOpen(false);
                        setShowCreateWsModal(true);
                      }}
                      className="w-full flex items-center gap-2 p-2 rounded-lg text-xs text-brand-400 hover:bg-slate-800 hover:text-brand-300 font-medium transition"
                    >
                      <Plus className="w-3.5 h-3.5" />
                      <span>Create New Workspace</span>
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Center: Navigation Links */}
          <nav className="hidden md:flex items-center gap-1">
            {navItems.map((item) => (
              <button
                key={item.id}
                onClick={() => onSelectTab(item.id)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition ${
                  currentTab === item.id
                    ? 'bg-slate-800 text-white shadow-sm border border-slate-700'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
                }`}
              >
                {item.label}
              </button>
            ))}
          </nav>

          {/* Right: User Menu */}
          <div className="relative" ref={userRef}>
            <button
              type="button"
              onClick={() => setUserMenuOpen(!userMenuOpen)}
              className="flex items-center gap-2.5 p-1 pl-2.5 rounded-xl bg-slate-800/50 hover:bg-slate-800 border border-slate-800 text-xs text-slate-300 transition"
            >
              <span className="font-medium max-w-[100px] truncate">{user?.name}</span>
              <div className="w-7 h-7 rounded-lg bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white font-bold text-xs uppercase shadow">
                {user?.name?.charAt(0) || 'U'}
              </div>
            </button>

            {userMenuOpen && (
              <div className="absolute right-0 mt-2 w-56 rounded-xl bg-slate-900 border border-slate-800 shadow-2xl p-2 z-50 animate-in fade-in zoom-in-95 duration-100">
                <div className="p-2 border-b border-slate-800 mb-1">
                  <p className="text-xs font-semibold text-white truncate">{user?.name}</p>
                  <p className="text-[11px] text-slate-400 truncate">{user?.email}</p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    onSelectTab('settings');
                    setUserMenuOpen(false);
                  }}
                  className="w-full flex items-center gap-2.5 p-2 rounded-lg text-xs text-slate-300 hover:bg-slate-800 hover:text-white transition"
                >
                  <Settings className="w-3.5 h-3.5 text-slate-400" />
                  <span>Workspace Settings</span>
                </button>
                <div className="my-1 border-t border-slate-800"></div>
                <button
                  type="button"
                  onClick={logout}
                  className="w-full flex items-center gap-2.5 p-2 rounded-lg text-xs text-rose-400 hover:bg-rose-500/10 transition"
                >
                  <LogOut className="w-3.5 h-3.5" />
                  <span>Sign Out</span>
                </button>
              </div>
            )}
          </div>
        </div>
      </header>

      {/* Create Workspace Modal */}
      {showCreateWsModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 space-y-5 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-brand-500/10 border border-brand-500/20 flex items-center justify-center text-brand-400">
                  <Building2 className="w-4 h-4" />
                </div>
                <h3 className="text-base font-bold text-white">Create New Workspace</h3>
              </div>
              <button
                onClick={() => setShowCreateWsModal(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {createWsError && (
              <div className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs">
                {createWsError}
              </div>
            )}

            <form onSubmit={handleCreateWorkspace} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">
                  Workspace Name
                </label>
                <input
                  type="text"
                  required
                  value={newWsName}
                  onChange={(e) => setNewWsName(e.target.value)}
                  placeholder="e.g. Apex Marketing Studio"
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white placeholder-slate-500 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">
                  Workspace Timezone
                </label>
                <select
                  value={newWsTimezone}
                  onChange={(e) => setNewWsTimezone(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition"
                >
                  <option value="UTC">UTC (Universal Time)</option>
                  <option value="America/New_York">Eastern Time (US & Canada)</option>
                  <option value="America/Chicago">Central Time (US & Canada)</option>
                  <option value="America/Denver">Mountain Time (US & Canada)</option>
                  <option value="America/Los_Angeles">Pacific Time (US & Canada)</option>
                  <option value="Europe/London">London (GMT / BST)</option>
                  <option value="Europe/Paris">Paris, Berlin, Rome (CET)</option>
                  <option value="Asia/Tokyo">Tokyo (JST)</option>
                  <option value="Asia/Dubai">Dubai (GST)</option>
                  <option value="Asia/Dhaka">Dhaka (BST)</option>
                  <option value="Australia/Sydney">Sydney (AEST)</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowCreateWsModal(false)}
                  className="py-2 px-4 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={creatingWs || !newWsName.trim()}
                  className="py-2 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 flex items-center gap-2 transition disabled:opacity-50"
                >
                  {creatingWs ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" /> Creating...
                    </>
                  ) : (
                    'Create Workspace'
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
