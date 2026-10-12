import React, { useState, useEffect } from 'react';
import { apiRequest } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { 
  Share2, 
  Calendar, 
  Clock, 
  CheckCircle2, 
  AlertCircle, 
  FileText, 
  XCircle, 
  Radio, 
  Sparkles, 
  ArrowRight, 
  RefreshCw,
  Loader2
} from 'lucide-react';

export default function DashboardPage({ onNavigateTab }) {
  const { user, activeWorkspace } = useAuth();
  const [stats, setStats] = useState(null);
  const [activity, setActivity] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const loadDashboardData = async () => {
    setLoading(true);
    setError(null);
    try {
      const [statsRes, activityRes] = await Promise.all([
        apiRequest('/api/dashboard/stats'),
        apiRequest('/api/dashboard/activity'),
      ]);

      if (statsRes.success) setStats(statsRes.data);
      if (activityRes.success) setActivity(activityRes.data || []);
    } catch (err) {
      setError(err.message || 'Failed to load dashboard data.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (activeWorkspace) {
      loadDashboardData();
    }
  }, [activeWorkspace]);

  return (
    <div className="space-y-8 max-w-7xl mx-auto">
      {/* Top Banner */}
      <div className="p-8 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-900/90 to-brand-950/40 border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="space-y-2 max-w-2xl">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">
            <CheckCircle2 className="w-3.5 h-3.5" /> Workspace: {activeWorkspace?.name}
          </div>
          <h1 className="text-3xl font-extrabold tracking-tight text-white">
            Welcome back, {user?.name} 👋
          </h1>
          <p className="text-sm text-slate-400 leading-relaxed">
            Here is your cross-platform social publishing overview for this workspace. All scheduled campaigns, accounts, and analytics are isolated to your team.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <button
            onClick={() => onNavigateTab('composer')}
            className="py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 flex items-center gap-2 transition"
          >
            <span>Compose Post</span>
            <ArrowRight className="w-3.5 h-3.5" />
          </button>
          <button
            onClick={() => onNavigateTab('accounts')}
            className="py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 flex items-center gap-2 transition"
          >
            <Share2 className="w-3.5 h-3.5 text-brand-400" />
            <span>Connect Channel</span>
          </button>
          <button
            onClick={loadDashboardData}
            disabled={loading}
            title="Refresh Data"
            className="p-2.5 rounded-xl bg-slate-800/80 hover:bg-slate-800 border border-slate-700 text-slate-400 hover:text-white transition"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      {error && (
        <div className="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm flex items-center gap-2">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* KPI Cards */}
      {loading && !stats ? (
        <div className="py-12 flex justify-center text-slate-500">
          <Loader2 className="w-8 h-8 animate-spin text-brand-500" />
        </div>
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          {/* Card 1: Accounts */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Channels</span>
              <div className="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                <Radio className="w-4 h-4" />
              </div>
            </div>
            <div>
              <span className="text-2xl font-black text-white">{stats?.accounts?.total_active ?? 0}</span>
              <div className="flex items-center gap-1.5 mt-1 text-[11px] text-slate-400">
                <span className="text-amber-400 font-medium">{stats?.accounts?.sandbox_count ?? 0} Sandbox</span>
                <span>•</span>
                <span className="text-emerald-400 font-medium">{stats?.accounts?.live_count ?? 0} Live</span>
              </div>
            </div>
          </div>

          {/* Card 2: Scheduled */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Scheduled</span>
              <div className="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center">
                <Clock className="w-4 h-4" />
              </div>
            </div>
            <div>
              <span className="text-2xl font-black text-white">{stats?.posts?.scheduled ?? 0}</span>
              <p className="mt-1 text-[11px] text-slate-400">In Queue for Publish</p>
            </div>
          </div>

          {/* Card 3: Published */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Published</span>
              <div className="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                <CheckCircle2 className="w-4 h-4" />
              </div>
            </div>
            <div>
              <span className="text-2xl font-black text-white">{stats?.posts?.published ?? 0}</span>
              <p className="mt-1 text-[11px] text-slate-400">Delivered Successfully</p>
            </div>
          </div>

          {/* Card 4: Drafts */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Drafts</span>
              <div className="w-8 h-8 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center">
                <FileText className="w-4 h-4" />
              </div>
            </div>
            <div>
              <span className="text-2xl font-black text-white">{stats?.posts?.draft ?? 0}</span>
              <p className="mt-1 text-[11px] text-slate-400">Work in Progress</p>
            </div>
          </div>

          {/* Card 5: Failed */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-lg flex flex-col justify-between col-span-2 md:col-span-1">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Failed</span>
              <div className="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-400 flex items-center justify-center">
                <XCircle className="w-4 h-4" />
              </div>
            </div>
            <div>
              <span className="text-2xl font-black text-white">{stats?.posts?.failed ?? 0}</span>
              <p className="mt-1 text-[11px] text-slate-400">Needs Operator Review</p>
            </div>
          </div>
        </div>
      )}

      {/* Main Grid: Upcoming Scheduled Posts + Recent Activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Upcoming Posts Widget */}
        <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-4">
          <div className="flex items-center justify-between pb-3 border-b border-slate-800">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <Clock className="w-4 h-4 text-brand-400" /> Upcoming Scheduled Posts
            </h2>
            <button
              onClick={() => onNavigateTab('calendar')}
              className="text-xs font-medium text-brand-400 hover:text-brand-300 transition"
            >
              Open Calendar →
            </button>
          </div>

          {!stats?.upcoming_scheduled_posts?.length ? (
            <div className="py-12 text-center text-slate-500 space-y-2">
              <Calendar className="w-8 h-8 mx-auto text-slate-600" />
              <p className="text-xs">No upcoming posts scheduled in this workspace.</p>
              <button
                onClick={() => onNavigateTab('composer')}
                className="text-xs text-brand-400 hover:underline font-semibold"
              >
                Schedule your first post
              </button>
            </div>
          ) : (
            <div className="space-y-3">
              {stats.upcoming_scheduled_posts.map((post) => (
                <div
                  key={post.id}
                  className="p-4 rounded-xl bg-slate-950/70 border border-slate-800/80 hover:border-slate-700 transition space-y-2"
                >
                  <div className="flex items-center justify-between text-xs text-slate-400">
                    <span className="flex items-center gap-1.5 font-medium text-slate-300">
                      <Clock className="w-3.5 h-3.5 text-blue-400" />
                      {new Date(post.scheduled_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                    </span>
                    <span className="text-[11px]">By {post.author_name}</span>
                  </div>
                  <p className="text-xs text-slate-200 line-clamp-2">{post.default_caption}</p>
                  <div className="flex items-center gap-1.5 pt-1">
                    {post.target_platforms.map((plat) => (
                      <span
                        key={plat}
                        className="capitalize px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-800 text-slate-300 border border-slate-700"
                      >
                        {plat}
                      </span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Recent Publishing Activity Audit Log */}
        <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-4">
          <div className="flex items-center justify-between pb-3 border-b border-slate-800">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-emerald-400" /> Publishing Audit Trail
            </h2>
            <span className="text-xs text-slate-500 font-medium">Real-time DB logs</span>
          </div>

          {!activity.length ? (
            <div className="py-12 text-center text-slate-500 space-y-2">
              <FileText className="w-8 h-8 mx-auto text-slate-600" />
              <p className="text-xs">No publishing activity logged yet.</p>
            </div>
          ) : (
            <div className="space-y-3 max-h-[380px] overflow-y-auto pr-1">
              {activity.map((log) => (
                <div
                  key={log.id}
                  className="p-3.5 rounded-xl bg-slate-950/70 border border-slate-800/80 flex items-start justify-between gap-3 text-xs"
                >
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="capitalize font-bold text-white">{log.platform}</span>
                      <span className="text-slate-500">•</span>
                      <span className="text-slate-400">{log.account_name}</span>
                    </div>
                    <p className="text-slate-300 text-[11px] line-clamp-1">{log.message}</p>
                    <span className="text-[10px] text-slate-500">
                      {new Date(log.created_at).toLocaleString()}
                    </span>
                  </div>

                  <div className="flex flex-col items-end gap-1 shrink-0">
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                      log.status === 'success'
                        ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                        : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'
                    }`}>
                      {log.status}
                    </span>

                    {log.is_simulated ? (
                      <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded border border-amber-500/20">
                        <Sparkles className="w-2.5 h-2.5" /> Simulation
                      </span>
                    ) : (
                      <span className="text-[10px] font-semibold text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">
                        Live Publish
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
