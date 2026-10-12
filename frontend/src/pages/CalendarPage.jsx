import React, { useState, useEffect, useCallback } from 'react';
import { apiRequest } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { 
  Calendar as CalendarIcon, 
  ChevronLeft, 
  ChevronRight, 
  Clock, 
  Edit3, 
  X, 
  Loader2, 
  AlertCircle, 
  CheckCircle2, 
  Plus, 
  RefreshCw
} from 'lucide-react';

const PLATFORM_ICONS = {
  facebook: '📘',
  instagram: '📷',
  twitter: '𝕏',
  tiktok: '🎵',
  youtube: '▶️',
};

const STATUS_COLORS = {
  draft: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
  scheduled: 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30 font-medium',
  publishing: 'bg-purple-500/15 text-purple-300 border-purple-500/30 animate-pulse',
  published: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
  partially_published: 'bg-teal-500/10 text-teal-400 border-teal-500/20',
  failed: 'bg-rose-500/10 text-rose-400 border-rose-500/20',
};

export default function CalendarPage({ onNavigateTab, onEditPost }) {
  const { activeWorkspace } = useAuth();

  // View state
  const [currentDate, setCurrentDate] = useState(() => new Date());
  const [todayStr] = useState(() => new Date().toISOString().slice(0, 10));
  const [viewMode, setViewMode] = useState('month'); // 'month' | 'week' | 'day'
  const [platformFilter, setPlatformFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('');

  // Data state
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedPost, setSelectedPost] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [actionSuccess, setActionSuccess] = useState(null);

  // Compute start and end dates based on viewMode and currentDate
  const getDateRange = useCallback(() => {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    if (viewMode === 'month') {
      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0, 23, 59, 59);
      // Pad to full weeks
      const start = new Date(firstDay);
      start.setDate(firstDay.getDate() - firstDay.getDay());
      start.setHours(0, 0, 0, 0);

      const end = new Date(lastDay);
      end.setDate(lastDay.getDate() + (6 - lastDay.getDay()));
      end.setHours(23, 59, 59, 999);

      return { start, end };
    }

    if (viewMode === 'week') {
      const start = new Date(currentDate);
      start.setDate(currentDate.getDate() - currentDate.getDay());
      start.setHours(0, 0, 0, 0);

      const end = new Date(start);
      end.setDate(start.getDate() + 6);
      end.setHours(23, 59, 59, 999);

      return { start, end };
    }

    // Day view
    const start = new Date(currentDate);
    start.setHours(0, 0, 0, 0);
    const end = new Date(currentDate);
    end.setHours(23, 59, 59, 999);
    return { start, end };
  }, [currentDate, viewMode]);

  const loadCalendarPosts = async () => {
    if (!activeWorkspace) return;
    setLoading(true);
    setError(null);

    const { start, end } = getDateRange();
    const startStr = start.toISOString().slice(0, 19).replace('T', ' ');
    const endStr = end.toISOString().slice(0, 19).replace('T', ' ');

    let url = `/api/calendar?start=${encodeURIComponent(startStr)}&end=${encodeURIComponent(endStr)}`;
    if (platformFilter && platformFilter !== 'all') {
      url += `&platform=${encodeURIComponent(platformFilter)}`;
    }
    if (statusFilter) {
      url += `&status=${encodeURIComponent(statusFilter)}`;
    }

    try {
      const res = await apiRequest(url);
      if (res.success && Array.isArray(res.data?.posts)) {
        setPosts(res.data.posts);
      }
    } catch (err) {
      setError(err.message || 'Failed to load calendar events.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!activeWorkspace) return;
    let ignore = false;
    const fetchCalendar = async () => {
      setError(null);
      const { start, end } = getDateRange();
      const startStr = start.toISOString().slice(0, 19).replace('T', ' ');
      const endStr = end.toISOString().slice(0, 19).replace('T', ' ');

      let url = `/api/calendar?start=${encodeURIComponent(startStr)}&end=${encodeURIComponent(endStr)}`;
      if (platformFilter && platformFilter !== 'all') {
        url += `&platform=${encodeURIComponent(platformFilter)}`;
      }
      if (statusFilter) {
        url += `&status=${encodeURIComponent(statusFilter)}`;
      }

      try {
        const res = await apiRequest(url);
        if (!ignore && res.success && Array.isArray(res.data?.posts)) {
          setPosts(res.data.posts);
        }
      } catch (err) {
        if (!ignore) {
          setError(err.message || 'Failed to load calendar events.');
        }
      } finally {
        if (!ignore) {
          setLoading(false);
        }
      }
    };

    fetchCalendar();
    return () => {
      ignore = true;
    };
  }, [activeWorkspace, currentDate, viewMode, platformFilter, statusFilter, getDateRange]);

  // Navigation handlers
  const handlePrev = () => {
    const next = new Date(currentDate);
    if (viewMode === 'month') {
      next.setMonth(next.getMonth() - 1);
    } else if (viewMode === 'week') {
      next.setDate(next.getDate() - 7);
    } else {
      next.setDate(next.getDate() - 1);
    }
    setCurrentDate(next);
  };

  const handleNext = () => {
    const next = new Date(currentDate);
    if (viewMode === 'month') {
      next.setMonth(next.getMonth() + 1);
    } else if (viewMode === 'week') {
      next.setDate(next.getDate() + 7);
    } else {
      next.setDate(next.getDate() + 1);
    }
    setCurrentDate(next);
  };

  const handleToday = () => {
    setCurrentDate(new Date());
  };

  // Actions on selected post
  const handleCancelSchedule = async (postId) => {
    if (!window.confirm('Are you sure you want to cancel scheduling and revert this post to draft?')) {
      return;
    }
    setActionLoading(true);
    try {
      const res = await apiRequest(`/api/posts/${postId}/cancel-schedule`, { method: 'POST' });
      if (res.success) {
        setActionSuccess('Post schedule cancelled and reverted to draft.');
        loadCalendarPosts();
        if (selectedPost?.id === postId) {
          setSelectedPost(prev => ({ ...prev, status: 'draft', scheduled_at: null }));
        }
      }
    } catch (err) {
      alert(err.message || 'Failed to cancel schedule.');
    } finally {
      setActionLoading(false);
    }
  };

  const handlePublishNow = async (postId) => {
    setActionLoading(true);
    try {
      const res = await apiRequest(`/api/posts/${postId}/publish`, { method: 'POST' });
      if (res.success) {
        setActionSuccess('Post published successfully in sandbox simulation.');
        loadCalendarPosts();
        if (selectedPost?.id === postId) {
          setSelectedPost(prev => ({ ...prev, status: res.data?.post_status || 'published' }));
        }
      }
    } catch (err) {
      alert(err.message || 'Failed to publish post.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleDeletePost = async (postId) => {
    if (!window.confirm('Are you sure you want to permanently delete this post?')) {
      return;
    }
    setActionLoading(true);
    try {
      const res = await apiRequest(`/api/posts/${postId}`, { method: 'DELETE' });
      if (res.success) {
        setSelectedPost(null);
        loadCalendarPosts();
      }
    } catch (err) {
      alert(err.message || 'Failed to delete post.');
    } finally {
      setActionLoading(false);
    }
  };

  // Helper to format title header
  const getHeaderTitle = () => {
    if (viewMode === 'month') {
      return currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    if (viewMode === 'week') {
      const { start, end } = getDateRange();
      return `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} – ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
    }
    return currentDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  };

  // Group posts by day key YYYY-MM-DD
  const getPostsForDay = (date) => {
    const key = date.toISOString().slice(0, 10);
    return posts.filter(p => {
      const postDate = new Date(p.calendar_date || p.scheduled_at || p.created_at);
      return postDate.toISOString().slice(0, 10) === key;
    });
  };

  // Generate Month Grid Days
  const renderMonthDays = () => {
    const { start, end } = getDateRange();
    const days = [];
    const curr = new Date(start);

    while (curr <= end) {
      days.push(new Date(curr));
      curr.setDate(curr.getDate() + 1);
    }

    const activeMonth = currentDate.getMonth();

    return (
      <div className="grid grid-cols-7 gap-px bg-slate-800 rounded-2xl overflow-hidden border border-slate-800">
        {/* Day headers */}
        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => (
          <div key={d} className="bg-slate-900/90 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
            {d}
          </div>
        ))}

        {/* Days grid cells */}
        {days.map(d => {
          const dayKey = d.toISOString().slice(0, 10);
          const isToday = dayKey === todayStr;
          const isCurrentMonth = d.getMonth() === activeMonth;
          const dayPosts = getPostsForDay(d);

          return (
            <div 
              key={dayKey}
              className={`min-h-[120px] p-2 bg-slate-950 transition hover:bg-slate-900/60 flex flex-col justify-between ${
                !isCurrentMonth ? 'opacity-40 bg-slate-950/60' : ''
              }`}
            >
              <div className="flex items-center justify-between">
                <span className={`inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-semibold ${
                  isToday 
                    ? 'bg-brand-600 text-white font-bold shadow-md shadow-brand-600/30' 
                    : isCurrentMonth ? 'text-slate-300' : 'text-slate-500'
                }`}>
                  {d.getDate()}
                </span>
                {dayPosts.length > 0 && (
                  <span className="text-[10px] text-slate-500 font-medium">
                    {dayPosts.length} post{dayPosts.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>

              {/* Post items in cell */}
              <div className="mt-1 space-y-1 overflow-y-auto max-h-[85px] scrollbar-none">
                {dayPosts.map(p => (
                  <button
                    key={p.id}
                    onClick={() => setSelectedPost(p)}
                    className={`w-full text-left p-1.5 rounded-lg border text-[11px] truncate flex items-center gap-1.5 transition ${
                      STATUS_COLORS[p.status] || 'bg-slate-800 text-slate-300 border-slate-700'
                    }`}
                  >
                    <span className="shrink-0 text-xs">
                      {p.targets?.[0] ? PLATFORM_ICONS[p.targets[0].platform] : '📄'}
                    </span>
                    <span className="truncate flex-1 font-medium">
                      {p.default_caption || 'Untitled Post'}
                    </span>
                    {p.scheduled_at && (
                      <span className="text-[9px] text-slate-400 shrink-0">
                        {new Date(p.scheduled_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    )}
                  </button>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // Generate Week Grid
  const renderWeekDays = () => {
    const { start } = getDateRange();
    const days = [];
    const curr = new Date(start);

    for (let i = 0; i < 7; i++) {
      days.push(new Date(curr));
      curr.setDate(curr.getDate() + 1);
    }

    return (
      <div className="grid grid-cols-1 md:grid-cols-7 gap-3">
        {days.map(d => {
          const dayKey = d.toISOString().slice(0, 10);
          const isToday = dayKey === todayStr;
          const dayPosts = getPostsForDay(d);

          return (
            <div 
              key={dayKey}
              className={`rounded-2xl p-3 border flex flex-col min-h-[360px] ${
                isToday 
                  ? 'bg-slate-900 border-brand-500/40 shadow-lg shadow-brand-500/5' 
                  : 'bg-slate-900/60 border-slate-800'
              }`}
            >
              <div className="pb-2 border-b border-slate-800/80 flex items-center justify-between">
                <div>
                  <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                    {d.toLocaleDateString('en-US', { weekday: 'short' })}
                  </p>
                  <p className={`text-base font-bold ${isToday ? 'text-brand-400' : 'text-white'}`}>
                    {d.getDate()}
                  </p>
                </div>
                {isToday && (
                  <span className="px-1.5 py-0.5 rounded text-[10px] bg-brand-500/20 text-brand-300 font-semibold border border-brand-500/30">
                    Today
                  </span>
                )}
              </div>

              {/* Day Posts List */}
              <div className="mt-3 flex-1 space-y-2 overflow-y-auto">
                {dayPosts.length === 0 ? (
                  <p className="text-[11px] text-slate-600 text-center py-6">No scheduled posts</p>
                ) : (
                  dayPosts.map(p => (
                    <button
                      key={p.id}
                      onClick={() => setSelectedPost(p)}
                      className={`w-full text-left p-2.5 rounded-xl border text-xs space-y-1.5 transition hover:scale-[1.01] ${
                        STATUS_COLORS[p.status] || 'bg-slate-800 text-slate-300 border-slate-700'
                      }`}
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-1">
                          {p.targets?.map(t => (
                            <span key={t.id} title={t.platform} className="text-xs">
                              {PLATFORM_ICONS[t.platform] || '📄'}
                            </span>
                          ))}
                        </div>
                        <span className="capitalize text-[10px] font-semibold">
                          {p.status}
                        </span>
                      </div>
                      <p className="line-clamp-2 text-slate-200 font-medium">
                        {p.default_caption || 'No caption'}
                      </p>
                      {p.scheduled_at && (
                        <div className="flex items-center gap-1 text-[10px] text-slate-400 pt-1">
                          <Clock className="w-3 h-3" />
                          <span>{new Date(p.scheduled_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                        </div>
                      )}
                    </button>
                  ))
                )}
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // Generate Daily Agenda List
  const renderDailyAgenda = () => {
    const dayPosts = getPostsForDay(currentDate);

    return (
      <div className="rounded-2xl bg-slate-900 border border-slate-800 p-6 space-y-4">
        <div className="flex items-center justify-between pb-4 border-b border-slate-800">
          <div>
            <h3 className="text-lg font-bold text-white">Daily Agenda</h3>
            <p className="text-xs text-slate-400">
              {dayPosts.length} post{dayPosts.length === 1 ? '' : 's'} scheduled for this date.
            </p>
          </div>
          <button
            onClick={() => onNavigateTab('composer')}
            className="py-2 px-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold flex items-center gap-1.5 transition shadow"
          >
            <Plus className="w-3.5 h-3.5" /> Schedule Post
          </button>
        </div>

        {dayPosts.length === 0 ? (
          <div className="text-center py-12 space-y-2">
            <CalendarIcon className="w-8 h-8 text-slate-600 mx-auto" />
            <p className="text-sm text-slate-400">No campaigns or scheduled posts on this day.</p>
            <p className="text-xs text-slate-500">Pick another day or compose a new post.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {dayPosts.map(p => (
              <div 
                key={p.id}
                className="p-4 rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-700 transition flex flex-col md:flex-row md:items-center justify-between gap-4"
              >
                <div className="space-y-2 max-w-xl">
                  <div className="flex items-center gap-2">
                    <span className={`px-2 py-0.5 rounded-full text-[10px] uppercase font-bold border ${STATUS_COLORS[p.status]}`}>
                      {p.status}
                    </span>
                    {p.scheduled_at && (
                      <span className="text-xs text-slate-400 flex items-center gap-1">
                        <Clock className="w-3.5 h-3.5 text-slate-500" />
                        {new Date(p.scheduled_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    )}
                    <span className="text-xs text-slate-500">
                      by {p.author_name}
                    </span>
                  </div>
                  <p className="text-sm text-white font-medium line-clamp-2">
                    {p.default_caption}
                  </p>
                  <div className="flex items-center gap-2 pt-1">
                    {p.targets?.map(t => (
                      <span key={t.id} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-900 border border-slate-800 text-[11px] text-slate-300">
                        <span>{PLATFORM_ICONS[t.platform]}</span>
                        <span className="capitalize">{t.platform}</span>
                      </span>
                    ))}
                  </div>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={() => setSelectedPost(p)}
                    className="py-1.5 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition"
                  >
                    View Details
                  </button>
                  <button
                    onClick={() => onEditPost && onEditPost(p)}
                    className="py-1.5 px-3 rounded-lg bg-brand-600/20 text-brand-300 hover:bg-brand-600/30 border border-brand-500/30 text-xs font-medium transition"
                  >
                    Edit
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      {/* Header bar */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-800">
        <div>
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-xl bg-brand-600/10 border border-brand-500/20 text-brand-400 flex items-center justify-center">
              <CalendarIcon className="w-5 h-5" />
            </div>
            <h1 className="text-2xl font-bold tracking-tight text-white">Content Calendar</h1>
          </div>
          <p className="text-xs text-slate-400 mt-1">
            Visual multi-channel scheduling overview for {activeWorkspace?.name} ({activeWorkspace?.timezone || 'UTC'}).
          </p>
        </div>

        {/* View mode toggle & create */}
        <div className="flex items-center gap-3">
          <div className="flex rounded-xl bg-slate-900 p-1 border border-slate-800">
            {['month', 'week', 'day'].map(mode => (
              <button
                key={mode}
                onClick={() => setViewMode(mode)}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold capitalize transition ${
                  viewMode === mode
                    ? 'bg-brand-600 text-white shadow'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                {mode}
              </button>
            ))}
          </div>

          <button
            onClick={() => onNavigateTab('composer')}
            className="py-2 px-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 flex items-center gap-1.5 transition"
          >
            <Plus className="w-4 h-4" />
            <span>Schedule Post</span>
          </button>
        </div>
      </div>

      {actionSuccess && (
        <div className="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs flex items-center justify-between">
          <div className="flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4" />
            <span>{actionSuccess}</span>
          </div>
          <button onClick={() => setActionSuccess(null)} className="text-slate-400 hover:text-white">
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {error && (
        <div className="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs flex items-center gap-2">
          <AlertCircle className="w-4 h-4" />
          <span>{error}</span>
        </div>
      )}

      {/* Filter and Date Controls */}
      <div className="p-4 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
        {/* Date Navigator */}
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1">
            <button
              onClick={handlePrev}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition"
              title="Previous"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={handleNext}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition"
              title="Next"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>

          <button
            onClick={handleToday}
            className="py-1.5 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200 transition"
          >
            Today
          </button>

          <span className="text-base font-bold text-white tracking-wide">
            {getHeaderTitle()}
          </span>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Platform selector */}
          <select
            value={platformFilter}
            onChange={(e) => setPlatformFilter(e.target.value)}
            className="px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-200 focus:outline-none focus:border-brand-500"
          >
            <option value="all">All Channels</option>
            <option value="facebook">📘 Facebook</option>
            <option value="instagram">📷 Instagram</option>
            <option value="twitter">𝕏 X (Twitter)</option>
            <option value="tiktok">🎵 TikTok</option>
            <option value="youtube">▶️ YouTube</option>
          </select>

          {/* Status selector */}
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-200 focus:outline-none focus:border-brand-500"
          >
            <option value="">All Statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="draft">Drafts</option>
            <option value="published">Published</option>
            <option value="failed">Failed</option>
          </select>

          <button
            onClick={loadCalendarPosts}
            disabled={loading}
            className="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white transition"
            title="Refresh Calendar"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      {/* Main View Area */}
      {loading ? (
        <div className="p-20 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col items-center justify-center text-slate-400 space-y-3">
          <Loader2 className="w-8 h-8 animate-spin text-brand-500" />
          <p className="text-xs font-semibold tracking-wider uppercase">Loading Calendar Events...</p>
        </div>
      ) : (
        <>
          {viewMode === 'month' && renderMonthDays()}
          {viewMode === 'week' && renderWeekDays()}
          {viewMode === 'day' && renderDailyAgenda()}
        </>
      )}

      {/* Post Detail Modal */}
      {selectedPost && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 space-y-5 animate-in fade-in zoom-in-95 duration-150 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <div className="flex items-center gap-2">
                <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold uppercase border ${STATUS_COLORS[selectedPost.status]}`}>
                  {selectedPost.status}
                </span>
                <span className="text-xs text-slate-400">
                  Post #{selectedPost.id}
                </span>
              </div>
              <button
                onClick={() => setSelectedPost(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Timing banner */}
            <div className="p-3.5 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-1">
              <div className="flex items-center justify-between">
                <span className="text-slate-400">Scheduled For:</span>
                <span className="font-semibold text-white">
                  {selectedPost.scheduled_at 
                    ? new Date(selectedPost.scheduled_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' }) 
                    : 'Not scheduled (Draft)'}
                </span>
              </div>
              {selectedPost.published_at && (
                <div className="flex items-center justify-between text-emerald-400">
                  <span>Published At:</span>
                  <span>{new Date(selectedPost.published_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}</span>
                </div>
              )}
              {selectedPost.scheduled_job && (
                <div className="flex items-center justify-between text-[11px] text-slate-500 pt-1 border-t border-slate-800">
                  <span>Queue Job:</span>
                  <span className="capitalize">{selectedPost.scheduled_job.status} (attempts: {selectedPost.scheduled_job.attempts}/{selectedPost.scheduled_job.max_attempts})</span>
                </div>
              )}
            </div>

            {/* Caption */}
            <div>
              <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">
                Post Caption
              </label>
              <div className="p-3.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-slate-200 whitespace-pre-wrap">
                {selectedPost.default_caption || <span className="text-slate-500 italic">No caption text</span>}
              </div>
            </div>

            {/* Attached Media */}
            {selectedPost.media && selectedPost.media.length > 0 && (
              <div>
                <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">
                  Attached Media ({selectedPost.media.length})
                </label>
                <div className="grid grid-cols-3 gap-2">
                  {selectedPost.media.map(m => (
                    <div key={m.id} className="aspect-square rounded-xl overflow-hidden bg-slate-950 border border-slate-800">
                      <img 
                        src={m.preview_url} 
                        alt={m.original_filename} 
                        className="w-full h-full object-cover" 
                      />
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Target Channels */}
            <div>
              <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">
                Target Channels ({selectedPost.targets?.length || 0})
              </label>
              <div className="space-y-1.5">
                {selectedPost.targets?.map(t => (
                  <div key={t.id} className="p-2.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="text-base">{PLATFORM_ICONS[t.platform] || '📄'}</span>
                      <span className="font-medium text-white">{t.account_name}</span>
                      {t.is_sandbox && (
                        <span className="text-[10px] px-1.5 py-0.2 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">
                          Sandbox
                        </span>
                      )}
                    </div>
                    <span className="text-[11px] capitalize text-slate-400">
                      {t.status}
                    </span>
                  </div>
                ))}
              </div>
            </div>

            {/* Action buttons */}
            <div className="pt-3 border-t border-slate-800 flex flex-wrap items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setSelectedPost(null)}
                className="py-2 px-3 rounded-xl text-xs font-semibold text-slate-400 hover:text-white transition"
              >
                Close
              </button>

              <button
                type="button"
                onClick={() => handleDeletePost(selectedPost.id)}
                disabled={actionLoading}
                className="py-2 px-3 rounded-xl text-xs font-semibold text-rose-400 hover:bg-rose-500/10 transition"
              >
                Delete
              </button>

              {selectedPost.status === 'scheduled' && (
                <button
                  type="button"
                  onClick={() => handleCancelSchedule(selectedPost.id)}
                  disabled={actionLoading}
                  className="py-2 px-3.5 rounded-xl bg-amber-500/10 text-amber-300 hover:bg-amber-500/20 border border-amber-500/20 text-xs font-semibold transition"
                >
                  Cancel Schedule
                </button>
              )}

              {selectedPost.status !== 'published' && (
                <button
                  type="button"
                  onClick={() => handlePublishNow(selectedPost.id)}
                  disabled={actionLoading}
                  className="py-2 px-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow transition"
                >
                  Publish Now
                </button>
              )}

              <button
                type="button"
                onClick={() => {
                  if (onEditPost) {
                    onEditPost(selectedPost);
                  }
                  setSelectedPost(null);
                }}
                className="py-2 px-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 flex items-center gap-1.5 transition"
              >
                <Edit3 className="w-3.5 h-3.5" />
                <span>Edit in Composer</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
