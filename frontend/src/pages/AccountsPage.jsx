import React, { useState, useEffect } from 'react';
import { apiRequest } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { 
  Share2, 
  Trash2, 
  CheckCircle2, 
  AlertCircle, 
  ExternalLink, 
  Loader2, 
  Radio, 
  Sparkles,
  Info,
  X,
  RefreshCw
} from 'lucide-react';

const PLATFORM_CONFIG = {
  facebook: {
    name: 'Facebook Pages',
    color: 'from-blue-600 to-blue-500',
    badge: 'Meta Graph API v19+',
    description: 'Publish updates, images, and videos to Facebook Pages you manage.',
  },
  instagram: {
    name: 'Instagram Professional',
    color: 'from-pink-600 via-purple-600 to-amber-500',
    badge: 'Instagram Graph API',
    description: 'Post photos, carousels, and Reels to Creator or Business accounts.',
  },
  twitter: {
    name: 'X (Twitter)',
    color: 'from-slate-700 to-slate-900',
    badge: 'Twitter API v2',
    description: 'Publish tweets, threads, and media directly to your X timeline.',
  },
  tiktok: {
    name: 'TikTok',
    color: 'from-slate-900 via-teal-900 to-rose-900',
    badge: 'Content Posting API v2',
    description: 'Directly schedule and share short-form videos to TikTok creators.',
  },
  youtube: {
    name: 'YouTube',
    color: 'from-red-600 to-red-700',
    badge: 'YouTube Data API v3',
    description: 'Upload and schedule videos and Shorts to your verified YouTube channel.',
  },
};

export default function AccountsPage() {
  const { activeWorkspace } = useAuth();
  const [accounts, setAccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg] = useState(null);
  const [errorMsg, setErrorMsg] = useState(null);
  const [liveModalPlatform, setLiveModalPlatform] = useState(null);

  const fetchAccounts = async () => {
    setLoading(true);
    try {
      const res = await apiRequest('/api/accounts');
      if (res.success && res.data) {
        setAccounts(res.data);
      }
    } catch (err) {
      setErrorMsg(err.message || 'Failed to load social accounts.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (activeWorkspace) {
      fetchAccounts();
    }
  }, [activeWorkspace]);

  const handleConnectSandbox = async (platform) => {
    setActionLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    try {
      const res = await apiRequest('/api/accounts/sandbox/connect', {
        method: 'POST',
        body: JSON.stringify({ platform }),
      });
      if (res.success) {
        setSuccessMsg(res.message || `Connected ${platform} in Sandbox Mode.`);
        fetchAccounts();
      }
    } catch (err) {
      setErrorMsg(err.message || 'Failed to connect sandbox account.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleConnectLiveOAuth = async (platform) => {
    setActionLoading(true);
    setErrorMsg(null);
    try {
      const res = await apiRequest(`/api/accounts/oauth/url?platform=${platform}`);
      if (res.success && res.data?.oauth_url) {
        window.location.href = res.data.oauth_url;
      }
    } catch (err) {
      // In development when credentials aren't configured in .env, display informative instructions
      setLiveModalPlatform({
        platform,
        error: err.message,
      });
    } finally {
      setActionLoading(false);
    }
  };

  const handleDisconnect = async (accountId, accountName) => {
    if (!window.confirm(`Are you sure you want to disconnect ${accountName}?`)) {
      return;
    }
    setActionLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    try {
      const res = await apiRequest(`/api/accounts/${accountId}/disconnect`, {
        method: 'POST',
      });
      if (res.success) {
        setSuccessMsg(`Disconnected ${accountName} successfully.`);
        fetchAccounts();
      }
    } catch (err) {
      setErrorMsg(err.message || 'Failed to disconnect account.');
    } finally {
      setActionLoading(false);
    }
  };

  return (
    <div className="space-y-8 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
            <Share2 className="w-6 h-6 text-brand-400" /> Social Accounts
          </h1>
          <p className="text-sm text-slate-400 mt-1">
            Connect and manage social channels authorized for <span className="text-white font-medium">{activeWorkspace?.name}</span>.
          </p>
        </div>
        <button
          onClick={fetchAccounts}
          disabled={loading || actionLoading}
          className="inline-flex items-center gap-2 py-2 px-3 rounded-xl bg-slate-900 border border-slate-800 text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          Refresh Status
        </button>
      </div>

      {/* Notifications */}
      {successMsg && (
        <div className="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4 shrink-0" />
          <span>{successMsg}</span>
        </div>
      )}

      {errorMsg && (
        <div className="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm flex items-center gap-2">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{errorMsg}</span>
        </div>
      )}

      {/* Connected Accounts List */}
      <div className="space-y-4">
        <h2 className="text-base font-bold text-white flex items-center gap-2">
          Connected Channels ({accounts.length})
        </h2>

        {loading ? (
          <div className="py-12 flex justify-center text-slate-500">
            <Loader2 className="w-8 h-8 animate-spin text-brand-500" />
          </div>
        ) : accounts.length === 0 ? (
          <div className="p-8 rounded-2xl bg-slate-900/60 border border-slate-800 border-dashed text-center space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-slate-800/80 text-slate-400 flex items-center justify-center mx-auto">
              <Radio className="w-6 h-6" />
            </div>
            <h3 className="text-sm font-semibold text-white">No social channels connected yet</h3>
            <p className="text-xs text-slate-400 max-w-sm mx-auto">
              Connect a Sandbox channel below to start composing, scheduling, and previewing posts immediately.
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {accounts.map((acc) => {
              const cfg = PLATFORM_CONFIG[acc.platform] || {};
              return (
                <div
                  key={acc.id}
                  className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between hover:border-slate-700/80 transition"
                >
                  <div className="space-y-3">
                    <div className="flex items-start justify-between">
                      <div className="flex items-center gap-3">
                        <div className={`w-10 h-10 rounded-xl bg-gradient-to-tr ${cfg.color || 'from-slate-700 to-slate-800'} flex items-center justify-center text-white font-bold text-xs uppercase shadow`}>
                          {acc.platform.charAt(0)}
                        </div>
                        <div>
                          <h4 className="text-sm font-bold text-white leading-tight">{acc.account_name}</h4>
                          <p className="text-xs text-slate-400">@{acc.username || acc.account_id}</p>
                        </div>
                      </div>
                      <button
                        onClick={() => handleDisconnect(acc.id, acc.account_name)}
                        disabled={actionLoading}
                        title="Disconnect Account"
                        className="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>

                    <div className="flex items-center gap-2 pt-2">
                      {acc.is_sandbox ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-300 border border-amber-500/20">
                          <Sparkles className="w-3 h-3 text-amber-400" /> Sandbox / Dev Mode
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">
                          <CheckCircle2 className="w-3 h-3 text-emerald-400" /> Live OAuth
                        </span>
                      )}
                      <span className="capitalize text-[11px] text-slate-400 bg-slate-800/80 px-2 py-0.5 rounded border border-slate-700/60">
                        {acc.account_type || 'standard'}
                      </span>
                    </div>
                  </div>

                  <div className="pt-4 mt-3 border-t border-slate-800/80 text-[11px] text-slate-500 flex justify-between items-center">
                    <span>Connected {new Date(acc.created_at).toLocaleDateString()}</span>
                    <span className="text-emerald-400 font-medium flex items-center gap-1">
                      <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Active
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Available Platforms to Connect */}
      <div className="space-y-4 pt-4 border-t border-slate-800">
        <h2 className="text-base font-bold text-white">Supported Platform Integrations</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {Object.entries(PLATFORM_CONFIG).map(([key, cfg]) => {
            const isConnected = accounts.some((a) => a.platform === key);
            return (
              <div
                key={key}
                className="p-5 rounded-2xl bg-slate-900/80 border border-slate-800 flex flex-col justify-between space-y-4 hover:border-slate-700/60 transition"
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-xl bg-gradient-to-tr ${cfg.color} flex items-center justify-center text-white font-bold text-xs uppercase shadow`}>
                      {key.charAt(0)}
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-white">{cfg.name}</h4>
                      <span className="text-[10px] text-slate-400 font-mono">{cfg.badge}</span>
                    </div>
                  </div>
                  {isConnected && (
                    <span className="text-xs font-semibold text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded-full border border-emerald-500/20">
                      Linked
                    </span>
                  )}
                </div>

                <p className="text-xs text-slate-400 leading-relaxed">
                  {cfg.description}
                </p>

                <div className="flex items-center gap-2.5 pt-2">
                  <button
                    type="button"
                    onClick={() => handleConnectSandbox(key)}
                    disabled={actionLoading}
                    className="flex-1 py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700/80 text-white text-xs font-semibold border border-slate-700 flex items-center justify-center gap-1.5 transition disabled:opacity-50"
                  >
                    <Sparkles className="w-3.5 h-3.5 text-amber-400" />
                    <span>Connect Sandbox</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => handleConnectLiveOAuth(key)}
                    disabled={actionLoading}
                    className="py-2 px-3 rounded-xl bg-brand-600/20 hover:bg-brand-600/30 text-brand-300 text-xs font-semibold border border-brand-500/30 flex items-center gap-1.5 transition disabled:opacity-50"
                  >
                    <ExternalLink className="w-3.5 h-3.5" />
                    <span>Live OAuth</span>
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Live OAuth Configuration Instructions Modal */}
      {liveModalPlatform && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-lg bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 space-y-5 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                  <Info className="w-4 h-4" />
                </div>
                <h3 className="text-base font-bold text-white capitalize">
                  {liveModalPlatform.platform} Live OAuth Setup
                </h3>
              </div>
              <button
                onClick={() => setLiveModalPlatform(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="space-y-3 text-xs text-slate-300 leading-relaxed">
              <p className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300">
                {liveModalPlatform.error}
              </p>
              <p>
                To enable live publishing for {liveModalPlatform.platform}, add your registered developer credentials to <code className="bg-slate-950 px-1.5 py-0.5 rounded text-brand-300 font-mono">backend/.env</code>:
              </p>
              <pre className="p-3 rounded-xl bg-slate-950 border border-slate-800 font-mono text-[11px] text-slate-300 overflow-x-auto">
{liveModalPlatform.platform === 'facebook' && `FACEBOOK_CLIENT_ID=your_app_id\nFACEBOOK_CLIENT_SECRET=your_app_secret`}
{liveModalPlatform.platform === 'instagram' && `INSTAGRAM_CLIENT_ID=your_instagram_id\nINSTAGRAM_CLIENT_SECRET=your_instagram_secret`}
{liveModalPlatform.platform === 'twitter' && `TWITTER_CLIENT_ID=your_x_client_id\nTWITTER_CLIENT_SECRET=your_x_client_secret`}
{liveModalPlatform.platform === 'tiktok' && `TIKTOK_CLIENT_KEY=your_tiktok_client_key\nTIKTOK_CLIENT_SECRET=your_tiktok_secret`}
{liveModalPlatform.platform === 'youtube' && `GOOGLE_CLIENT_ID=your_google_client_id\nGOOGLE_CLIENT_SECRET=your_google_secret`}
              </pre>
              <p className="text-slate-400">
                Meanwhile, you can use <strong>Connect Sandbox</strong> to test all scheduler features, compose workflows, and live previews with zero external friction.
              </p>
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => {
                  const plat = liveModalPlatform.platform;
                  setLiveModalPlatform(null);
                  handleConnectSandbox(plat);
                }}
                className="py-2 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 transition flex items-center gap-1.5"
              >
                <Sparkles className="w-3.5 h-3.5" />
                Connect Sandbox Account Instead
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
