import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { apiRequest } from '../api/client';
import { 
  FileText, 
  Plus, 
  Trash2, 
  Edit3, 
  Send, 
  Calendar, 
  ExternalLink, 
  AlertCircle, 
  CheckCircle2, 
  Loader2, 
  Sparkles, 
  RefreshCw, 
  Filter 
} from 'lucide-react';

const PLATFORM_ICONS = {
  facebook: '📘',
  instagram: '📷',
  twitter: '𝕏',
  tiktok: '🎵',
  youtube: '▶️',
};

export default function PostsPage({ onNavigateTab, onEditPost }) {
  const { activeWorkspace } = useAuth();
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState(''); // '' | 'draft' | 'scheduled' | 'published' | 'failed'
  const [actionLoadingId, setActionLoadingId] = useState(null);
  const [feedback, setFeedback] = useState(null);

  useEffect(() => {
    loadPosts();
  }, [activeWorkspace?.id, statusFilter]);

  const loadPosts = async () => {
    setLoading(true);
    try {
      const query = statusFilter ? `?status=${statusFilter}` : '';
      const res = await apiRequest(`/api/posts${query}`);
      if (res.success && res.data?.posts) {
        setPosts(res.data.posts);
      }
    } catch (err) {
      console.error('Failed to load posts:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async (postId) => {
    if (!window.confirm(`Are you sure you want to delete post #${postId}?`)) {
      return;
    }
    setActionLoadingId(postId);
    try {
      const res = await apiRequest(`/api/posts/${postId}`, { method: 'DELETE' });
      if (res.success) {
        setPosts(posts.filter(p => p.id !== postId));
        setFeedback({ type: 'success', message: `Post #${postId} deleted successfully.` });
      } else {
        throw new Error(res.message || 'Delete failed.');
      }
    } catch (err) {
      setFeedback({ type: 'error', message: err.message || 'Failed to delete post.' });
    } finally {
      setActionLoadingId(null);
    }
  };

  const handlePublishNow = async (postId) => {
    setActionLoadingId(postId);
    setFeedback(null);
    try {
      const res = await apiRequest(`/api/posts/${postId}/publish`, { method: 'POST' });
      if (res.success) {
        setFeedback({ 
          type: 'success', 
          message: `Post #${postId} published! Status: ${res.data?.post_status || 'published'}` 
        });
        loadPosts();
      } else {
        throw new Error(res.message || 'Publishing failed.');
      }
    } catch (err) {
      setFeedback({ type: 'error', message: err.message || 'Publishing failed.' });
    } finally {
      setActionLoadingId(null);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-800">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-white">Posts & Drafts Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            View, schedule, filter, and inspect publishing statuses for all workspace campaigns.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={loadPosts}
            disabled={loading}
            className="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-slate-400 hover:text-white transition"
            title="Refresh Posts"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>

          <button
            type="button"
            onClick={() => onNavigateTab && onNavigateTab('composer')}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-semibold shadow-lg shadow-brand-500/20 transition"
          >
            <Plus className="w-4 h-4" />
            <span>Compose Post</span>
          </button>
        </div>
      </div>

      {feedback && (
        <div className={`p-4 rounded-xl border text-xs flex items-start gap-3 ${
          feedback.type === 'success' 
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' 
            : 'bg-rose-500/10 border-rose-500/30 text-rose-300'
        }`}>
          {feedback.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 shrink-0 mt-0.5" />
          ) : (
            <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
          )}
          <span>{feedback.message}</span>
        </div>
      )}

      {/* Filter Tabs */}
      <div className="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
        {[
          { id: '', label: 'All Posts' },
          { id: 'draft', label: 'Drafts' },
          { id: 'scheduled', label: 'Scheduled' },
          { id: 'published', label: 'Published' },
          { id: 'failed', label: 'Failed' },
        ].map(tab => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setStatusFilter(tab.id)}
            className={`px-3.5 py-1.5 rounded-xl font-semibold transition ${
              statusFilter === tab.id
                ? 'bg-brand-600 text-white shadow-sm'
                : 'bg-slate-900 border border-slate-800 text-slate-400 hover:text-white hover:bg-slate-850'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Posts List */}
      {loading ? (
        <div className="py-16 flex justify-center text-slate-500">
          <Loader2 className="w-8 h-8 animate-spin text-brand-500" />
        </div>
      ) : !posts.length ? (
        <div className="p-12 rounded-2xl bg-slate-900 border border-slate-800 text-center space-y-3">
          <FileText className="w-10 h-10 mx-auto text-slate-600" />
          <h3 className="text-base font-bold text-white">No posts found</h3>
          <p className="text-xs text-slate-400 max-w-sm mx-auto">
            {statusFilter 
              ? `There are no posts matching the "${statusFilter}" filter in this workspace.` 
              : 'You haven’t created any posts in this workspace yet.'}
          </p>
          <button
            type="button"
            onClick={() => onNavigateTab && onNavigateTab('composer')}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Create First Post</span>
          </button>
        </div>
      ) : (
        <div className="space-y-4">
          {posts.map(post => (
            <div
              key={post.id}
              className="p-5 rounded-2xl bg-slate-900 border border-slate-800 hover:border-slate-750 transition shadow-md space-y-4"
            >
              {/* Top row: ID, Status, Timestamps, Actions */}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-800/80">
                <div className="flex items-center gap-2.5">
                  <span className="font-mono font-bold text-xs text-brand-400">#{post.id}</span>
                  <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ${
                    post.status === 'published' 
                      ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                      : (post.status === 'scheduled' 
                          ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' 
                          : (post.status === 'draft' 
                              ? 'bg-slate-700/40 text-slate-300 border border-slate-700' 
                              : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'))
                  }`}>
                    {post.status}
                  </span>

                  {post.scheduled_at && (
                    <span className="text-[11px] text-slate-400 flex items-center gap-1">
                      <Calendar className="w-3 h-3 text-slate-500" />
                      <span>{new Date(post.scheduled_at).toLocaleString()}</span>
                    </span>
                  )}
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                  {post.status !== 'published' && (
                    <button
                      type="button"
                      disabled={actionLoadingId === post.id}
                      onClick={() => handlePublishNow(post.id)}
                      className="px-2.5 py-1.5 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 border border-emerald-500/30 text-xs font-semibold flex items-center gap-1 transition"
                      title="Publish Now"
                    >
                      {actionLoadingId === post.id ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Send className="w-3.5 h-3.5" />
                      )}
                      <span>Publish</span>
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={() => {
                      if (onEditPost) onEditPost(post);
                      if (onNavigateTab) onNavigateTab('composer');
                    }}
                    className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white transition"
                    title="Edit in Composer"
                  >
                    <Edit3 className="w-3.5 h-3.5" />
                  </button>

                  <button
                    type="button"
                    disabled={actionLoadingId === post.id}
                    onClick={() => handleDelete(post.id)}
                    className="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition"
                    title="Delete Post"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>

              {/* Caption Content */}
              <div className="text-xs text-slate-200 leading-relaxed whitespace-pre-wrap">
                {post.caption || <span className="italic text-slate-500">No base caption provided.</span>}
              </div>

              {/* Media Thumbnails */}
              {post.media?.length > 0 && (
                <div className="flex items-center gap-2 overflow-x-auto pb-1">
                  {post.media.map(m => (
                    <div 
                      key={m.id} 
                      className="w-16 h-16 rounded-lg overflow-hidden bg-slate-950 border border-slate-800 shrink-0 flex items-center justify-center relative"
                    >
                      {m.media_type === 'video' ? (
                        <span className="text-[10px] font-mono font-bold text-indigo-400">VIDEO</span>
                      ) : (
                        <img src={m.preview_url} alt="" className="w-full h-full object-cover" />
                      )}
                    </div>
                  ))}
                </div>
              )}

              {/* Targets List */}
              {post.targets?.length > 0 && (
                <div className="pt-2 border-t border-slate-800/60">
                  <span className="text-[10px] font-semibold text-slate-500 uppercase tracking-wider block mb-2">
                    Publish Targets ({post.targets.length})
                  </span>
                  <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                    {post.targets.map(t => (
                      <div
                        key={t.id}
                        className="p-2.5 rounded-xl bg-slate-950/70 border border-slate-800 text-xs flex items-center justify-between"
                      >
                        <div className="flex items-center gap-2 min-w-0">
                          <span>{PLATFORM_ICONS[t.platform] || '📡'}</span>
                          <span className="font-semibold text-slate-300 truncate max-w-[120px]">
                            {t.account_name}
                          </span>
                        </div>

                        <div className="flex items-center gap-1.5 shrink-0">
                          <span className={`px-1.5 py-0.5 rounded text-[9px] font-bold uppercase ${
                            t.status === 'published' 
                              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                              : (t.status === 'pending' 
                                  ? 'bg-amber-500/10 text-amber-400' 
                                  : 'bg-rose-500/10 text-rose-400')
                          }`}>
                            {t.status}
                          </span>

                          {t.platform_post_url && (
                            <a 
                              href={t.platform_post_url} 
                              target="_blank" 
                              rel="noreferrer" 
                              className="text-brand-400 hover:text-brand-300"
                              title="View published post"
                            >
                              <ExternalLink className="w-3 h-3" />
                            </a>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
