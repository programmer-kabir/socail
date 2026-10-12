import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { apiRequest } from '../api/client';
import MediaUploader from '../components/composer/MediaUploader';
import PlatformPreviews from '../components/composer/PlatformPreviews';
import { 
  Send, 
  Calendar, 
  FileText, 
  CheckCircle2, 
  AlertCircle, 
  Sparkles, 
  Loader2, 
  Eye, 
  Clock, 
  ExternalLink, 
  X,
  RefreshCw,
  FolderOpen
} from 'lucide-react';

const PLATFORM_ICONS = {
  facebook: '📘',
  instagram: '📷',
  twitter: '𝕏',
  tiktok: '🎵',
  youtube: '▶️',
};

export default function ComposerPage({ initialPost = null, onNavigateTab }) {
  const { activeWorkspace } = useAuth();

  // Accounts state
  const [accounts, setAccounts] = useState([]);
  const [loadingAccounts, setLoadingAccounts] = useState(true);

  // Form state
  const [postId, setPostId] = useState(initialPost?.id || null);
  const [selectedAccountIds, setSelectedAccountIds] = useState(
    initialPost?.targets ? initialPost.targets.map(t => t.social_account_id) : []
  );
  const [baseCaption, setBaseCaption] = useState(initialPost?.caption || '');
  const [customCaptions, setCustomCaptions] = useState({}); // { [accountId]: string }
  const [youtubeTitles, setYoutubeTitles] = useState({}); // { [accountId]: string }
  const [media, setMedia] = useState(initialPost?.media || []);
  
  // Scheduling state
  const [isScheduled, setIsScheduled] = useState(!!initialPost?.scheduled_at);
  const [scheduledAt, setScheduledAt] = useState(
    initialPost?.scheduled_at ? new Date(initialPost.scheduled_at).toISOString().slice(0, 16) : ''
  );

  // UI tabs & view state
  const [activeTab, setActiveTab] = useState('base'); // 'base' | accountId string
  const [userPreviewAccountId, setUserPreviewAccountId] = useState(null);
  const previewAccountId = (userPreviewAccountId && selectedAccountIds.includes(userPreviewAccountId))
    ? userPreviewAccountId
    : (selectedAccountIds[0] || null);
  const setPreviewAccountId = setUserPreviewAccountId;

  const [minScheduleTime, setMinScheduleTime] = useState(() => new Date().toISOString().slice(0, 16));

  useEffect(() => {
    if (!isScheduled) return;
    const timer = setInterval(() => {
      setMinScheduleTime(new Date().toISOString().slice(0, 16));
    }, 60000);
    return () => clearInterval(timer);
  }, [isScheduled]);

  const [submitting, setSubmitting] = useState(false);
  const [feedback, setFeedback] = useState(null); // { type: 'success' | 'error', message: string, details?: any }
  const [publishResult, setPublishResult] = useState(null); // Publishing response breakdown

  // Drafts modal
  const [showDraftsModal, setShowDraftsModal] = useState(false);
  const [draftsList, setDraftsList] = useState([]);
  const [loadingDrafts, setLoadingDrafts] = useState(false);

  const loadDrafts = async () => {
    setLoadingDrafts(true);
    try {
      const res = await apiRequest('/api/posts?status=draft');
      if (res.success && res.data?.posts) {
        setDraftsList(res.data.posts);
      }
    } catch (err) {
      console.error('Failed to load drafts:', err);
    } finally {
      setLoadingDrafts(false);
    }
  };

  // Fetch accounts on workspace change
  useEffect(() => {
    let ignore = false;
    const fetchAccounts = async () => {
      try {
        const res = await apiRequest('/api/accounts');
        if (!ignore && res.success && Array.isArray(res.data)) {
          setAccounts(res.data);
          // Default to selecting all connected accounts if creating new post and none selected
          if (!postId && !selectedAccountIds.length && res.data.length > 0) {
            setSelectedAccountIds([res.data[0].id]);
          }
        }
      } catch (err) {
        if (!ignore) {
          console.error('Failed to load accounts:', err);
        }
      } finally {
        if (!ignore) {
          setLoadingAccounts(false);
        }
      }
    };

    fetchAccounts();
    return () => {
      ignore = true;
    };
  }, [activeWorkspace?.id, postId, selectedAccountIds.length]);

  // Update state whenever initialPost changes
  useEffect(() => {
    if (initialPost) {
      setPostId(initialPost.id);
      setBaseCaption(initialPost.caption || '');
      setMedia(initialPost.media || []);
      setIsScheduled(!!initialPost.scheduled_at);
      setScheduledAt(
        initialPost.scheduled_at 
          ? new Date(initialPost.scheduled_at).toISOString().slice(0, 16) 
          : ''
      );
      const targetIds = (initialPost.targets || []).map(t => t.social_account_id);
      setSelectedAccountIds(targetIds);
      const customCaps = {};
      (initialPost.targets || []).forEach(t => {
        if (t.custom_caption) {
          customCaps[t.social_account_id] = t.custom_caption;
        }
      });
      setCustomCaptions(customCaps);
    }
  }, [initialPost]);



  const handleCancelSchedule = async () => {
    if (!postId) return;
    setSubmitting(true);
    setFeedback(null);
    try {
      const res = await apiRequest(`/api/posts/${postId}/cancel-schedule`, {
        method: 'POST'
      });
      if (!res.success) {
        throw new Error(res.message || 'Failed to cancel schedule.');
      }
      setIsScheduled(false);
      setScheduledAt('');
      setFeedback({
        type: 'success',
        message: `Schedule cancelled for post #${postId}. It is now saved as a draft.`,
      });
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err.message || 'Failed to cancel schedule.',
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleSelectDraft = (draft) => {
    setPostId(draft.id);
    setBaseCaption(draft.caption || '');
    setMedia(draft.media || []);
    setIsScheduled(!!draft.scheduled_at);
    setScheduledAt(draft.scheduled_at ? new Date(draft.scheduled_at).toISOString().slice(0, 16) : '');
    
    const targetIds = (draft.targets || []).map(t => t.social_account_id);
    setSelectedAccountIds(targetIds);

    const customCaps = {};
    (draft.targets || []).forEach(t => {
      if (t.custom_caption) {
        customCaps[t.social_account_id] = t.custom_caption;
      }
    });
    setCustomCaptions(customCaps);

    setShowDraftsModal(false);
    setFeedback({
      type: 'success',
      message: `Loaded draft #${draft.id} into composer.`,
    });
  };

  const toggleAccount = (accountId) => {
    if (selectedAccountIds.includes(accountId)) {
      setSelectedAccountIds(selectedAccountIds.filter(id => id !== accountId));
      if (activeTab === String(accountId)) {
        setActiveTab('base');
      }
    } else {
      setSelectedAccountIds([...selectedAccountIds, accountId]);
    }
  };

  const handleSubmit = async (actionType) => {
    // actionType: 'draft' | 'schedule' | 'publish'
    setFeedback(null);
    setPublishResult(null);

    if (actionType !== 'draft') {
      if (!selectedAccountIds.length) {
        setFeedback({
          type: 'error',
          message: 'Please select at least one social account to publish or schedule.',
        });
        return;
      }
      if (!baseCaption.trim() && !media.length) {
        setFeedback({
          type: 'error',
          message: 'A post must contain either text caption or at least one media asset.',
        });
        return;
      }
      if (actionType === 'schedule') {
        if (!scheduledAt) {
          setFeedback({
            type: 'error',
            message: 'Please pick a future date and time to schedule this post.',
          });
          return;
        }
        if (new Date(scheduledAt) <= new Date()) {
          setFeedback({
            type: 'error',
            message: 'Scheduled date and time must be set in the future.',
          });
          return;
        }
      }
    }

    setSubmitting(true);

    const payload = {
      caption: baseCaption,
      status: actionType === 'draft' ? 'draft' : (actionType === 'schedule' ? 'scheduled' : 'publish_now'),
      target_account_ids: selectedAccountIds,
      custom_captions: customCaptions,
      media_ids: media.map(m => m.id),
      scheduled_at: actionType === 'schedule' ? scheduledAt : null,
    };

    try {
      const endpoint = postId ? `/api/posts/${postId}` : '/api/posts';
      const method = postId ? 'PUT' : 'POST';

      const res = await apiRequest(endpoint, {
        method,
        body: JSON.stringify(payload),
      });

      if (!res.success) {
        throw new Error(res.message || 'Operation failed.');
      }

      const postData = res.data?.post || res.data;
      if (postData?.id) {
        setPostId(postData.id);
      }

      if (actionType === 'publish') {
        const pubData = res.data?.publishing || {};
        setPublishResult(pubData);
        setFeedback({
          type: 'success',
          message: `Publication processed! Post status: ${pubData.post_status || 'completed'}.`,
        });
      } else if (actionType === 'schedule') {
        setFeedback({
          type: 'success',
          message: `Post successfully scheduled for ${new Date(scheduledAt).toLocaleString()}!`,
        });
      } else {
        setFeedback({
          type: 'success',
          message: `Draft successfully saved!`,
        });
      }
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err.message || 'Failed to process post.',
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleResetForm = () => {
    setPostId(null);
    setBaseCaption('');
    setCustomCaptions({});
    setYoutubeTitles({});
    setMedia([]);
    setIsScheduled(false);
    setScheduledAt('');
    setFeedback(null);
    setPublishResult(null);
    setActiveTab('base');
  };

  const activeAccount = accounts.find(a => a.id === previewAccountId);

  return (
    <div className="space-y-6">
      {/* Top Banner & Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-800">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-bold tracking-tight text-white">Multi-Platform Post Composer</h1>
            {postId && (
              <span className="px-2 py-0.5 rounded-full bg-slate-800 border border-slate-700 text-xs font-mono text-slate-300">
                Editing #{postId}
              </span>
            )}
          </div>
          <p className="text-xs text-slate-400 mt-0.5">
            Craft, customize, and publish synchronized content across Facebook, Instagram, X, TikTok, and YouTube.
          </p>
        </div>

        <div className="flex items-center gap-2">
          {onNavigateTab && (
            <button
              type="button"
              onClick={() => onNavigateTab('calendar')}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-xs font-semibold text-slate-300 transition"
            >
              <Calendar className="w-3.5 h-3.5 text-brand-400" />
              <span>Calendar</span>
            </button>
          )}

          <button
            type="button"
            onClick={() => {
              loadDrafts();
              setShowDraftsModal(true);
            }}
            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-xs font-semibold text-slate-300 transition"
          >
            <FolderOpen className="w-3.5 h-3.5 text-brand-400" />
            <span>Saved Drafts</span>
          </button>

          {postId && (
            <button
              type="button"
              onClick={handleResetForm}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-xs font-semibold text-slate-300 transition"
            >
              <RefreshCw className="w-3.5 h-3.5 text-slate-400" />
              <span>New Post</span>
            </button>
          )}
        </div>
      </div>

      {/* Global Alerts */}
      {feedback && (
        <div className={`p-4 rounded-xl border text-xs flex items-start gap-3 transition ${
          feedback.type === 'success' 
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' 
            : 'bg-rose-500/10 border-rose-500/30 text-rose-300'
        }`}>
          {feedback.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 shrink-0 text-emerald-400 mt-0.5" />
          ) : (
            <AlertCircle className="w-5 h-5 shrink-0 text-rose-400 mt-0.5" />
          )}
          <div className="space-y-1">
            <span className="font-semibold block">{feedback.message}</span>
          </div>
        </div>
      )}

      {/* Immediate Publishing Results Card */}
      {publishResult && (
        <div className="p-4 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">
              Publishing Execution Breakdown
            </span>
            <span className={`px-2 py-0.5 rounded text-[11px] font-bold uppercase ${
              publishResult.post_status === 'published' 
                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                : (publishResult.post_status === 'partially_published' 
                    ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' 
                    : 'bg-rose-500/10 text-rose-400 border border-rose-500/20')
            }`}>
              {publishResult.post_status}
            </span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
            {Object.entries(publishResult.targets || {}).map(([accId, tRes]) => {
              const matchedAcc = accounts.find(a => String(a.id) === String(accId));
              return (
                <div 
                  key={accId} 
                  className="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between"
                >
                  <div className="flex items-center gap-2">
                    <span className="text-base">{PLATFORM_ICONS[matchedAcc?.platform] || '📡'}</span>
                    <div>
                      <div className="font-semibold text-white">{matchedAcc?.account_name || `Target #${accId}`}</div>
                      <div className="text-[10px] text-slate-400">
                        {tRes.is_simulated ? 'Sandbox Simulation' : 'Live Gateway'}
                      </div>
                    </div>
                  </div>

                  <div className="text-right space-y-0.5">
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                      tRes.status === 'published' 
                        ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                        : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'
                    }`}>
                      {tRes.status}
                    </span>
                    {tRes.platform_post_url && (
                      <a 
                        href={tRes.platform_post_url} 
                        target="_blank" 
                        rel="noreferrer" 
                        className="flex items-center justify-end gap-1 text-[10px] text-brand-400 hover:underline"
                      >
                        <span>View Post</span>
                        <ExternalLink className="w-2.5 h-2.5" />
                      </a>
                    )}
                    {tRes.error && (
                      <div className="text-[10px] text-rose-400 max-w-[140px] truncate" title={tRes.error}>
                        {tRes.error}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Main Two-Column Composer Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        {/* Left Column: Form & Settings (7 cols) */}
        <div className="lg:col-span-7 space-y-6">
          {/* Section 1: Connected Channel Selection */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-md space-y-3">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                1. Select Target Social Accounts
              </label>
              <span className="text-xs text-slate-500">
                {selectedAccountIds.length} of {accounts.length} selected
              </span>
            </div>

            {loadingAccounts ? (
              <div className="py-6 flex justify-center text-slate-500">
                <Loader2 className="w-6 h-6 animate-spin text-brand-500" />
              </div>
            ) : !accounts.length ? (
              <div className="p-4 rounded-xl bg-slate-950 border border-slate-800 text-center space-y-2">
                <p className="text-xs text-slate-400">No social accounts connected in this workspace.</p>
                <button
                  type="button"
                  onClick={() => onNavigateTab && onNavigateTab('accounts')}
                  className="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold"
                >
                  Connect Accounts Now
                </button>
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                {accounts.map(acc => {
                  const isSelected = selectedAccountIds.includes(acc.id);
                  return (
                    <div
                      key={acc.id}
                      onClick={() => toggleAccount(acc.id)}
                      className={`p-3 rounded-xl border cursor-pointer transition flex items-center justify-between select-none ${
                        isSelected 
                          ? 'bg-brand-500/10 border-brand-500/40 text-white shadow-sm' 
                          : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'
                      }`}
                    >
                      <div className="flex items-center gap-2.5 min-w-0">
                        <span className="text-xl shrink-0">{PLATFORM_ICONS[acc.platform] || '📡'}</span>
                        <div className="truncate">
                          <div className="text-xs font-semibold truncate text-slate-200">
                            {acc.account_name}
                          </div>
                          <div className="text-[10px] text-slate-500 flex items-center gap-1.5">
                            <span className="capitalize">{acc.platform}</span>
                            {acc.is_sandbox ? (
                              <span className="text-amber-400 font-medium">Sandbox</span>
                            ) : (
                              <span className="text-emerald-400 font-medium">Live</span>
                            )}
                          </div>
                        </div>
                      </div>

                      <div className={`w-5 h-5 rounded-md flex items-center justify-center border text-xs shrink-0 transition ${
                        isSelected 
                          ? 'bg-brand-600 border-brand-500 text-white' 
                          : 'border-slate-700 bg-slate-900'
                      }`}>
                        {isSelected && '✓'}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Section 2: Caption Editor with Platform Customization Tabs */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-md space-y-4">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                2. Caption & Content Customization
              </label>
              <span className="text-xs text-slate-400 font-mono">
                {activeTab === 'base' 
                  ? `${baseCaption.length} chars` 
                  : `${(customCaptions[activeTab] || baseCaption).length} chars`}
              </span>
            </div>

            {/* Customization Tabs */}
            {selectedAccountIds.length > 0 && (
              <div className="flex items-center gap-1.5 overflow-x-auto pb-1 border-b border-slate-800 text-xs">
                <button
                  type="button"
                  onClick={() => setActiveTab('base')}
                  className={`px-3 py-1.5 rounded-lg font-semibold transition shrink-0 ${
                    activeTab === 'base'
                      ? 'bg-slate-800 text-white border border-slate-700 shadow-xs'
                      : 'text-slate-400 hover:text-white hover:bg-slate-800/50'
                  }`}
                >
                  Shared Base Caption
                </button>

                {selectedAccountIds.map(accId => {
                  const acc = accounts.find(a => a.id === accId);
                  if (!acc) return null;
                  const hasCustom = customCaptions[accId] !== undefined && customCaptions[accId] !== '';
                  return (
                    <button
                      key={accId}
                      type="button"
                      onClick={() => {
                        setActiveTab(String(accId));
                        setPreviewAccountId(accId);
                      }}
                      className={`px-3 py-1.5 rounded-lg font-semibold transition shrink-0 flex items-center gap-1.5 ${
                        activeTab === String(accId)
                          ? 'bg-brand-600 text-white shadow-xs'
                          : 'text-slate-400 hover:text-white hover:bg-slate-800/50'
                      }`}
                    >
                      <span>{PLATFORM_ICONS[acc.platform]}</span>
                      <span className="truncate max-w-[100px]">{acc.account_name}</span>
                      {hasCustom && (
                        <span className="w-1.5 h-1.5 rounded-full bg-amber-400" title="Customized caption" />
                      )}
                    </button>
                  );
                })}
              </div>
            )}

            {/* Base Caption Textarea */}
            {activeTab === 'base' ? (
              <div className="space-y-2">
                <textarea
                  rows={5}
                  value={baseCaption}
                  onChange={(e) => setBaseCaption(e.target.value)}
                  placeholder="What would you like to share? Write your base caption here..."
                  className="w-full rounded-xl bg-slate-950 border border-slate-800 p-3.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y leading-relaxed"
                />
                <p className="text-[11px] text-slate-500">
                  This base caption applies to all selected channels unless overridden in an account tab above.
                </p>
              </div>
            ) : (
              /* Platform Specific Override */
              (() => {
                const curAcc = accounts.find(a => String(a.id) === activeTab);
                const curCustomVal = customCaptions[activeTab] !== undefined ? customCaptions[activeTab] : baseCaption;
                return (
                  <div className="space-y-3">
                    <div className="p-3 rounded-xl bg-brand-500/10 border border-brand-500/20 text-xs text-brand-300 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Sparkles className="w-4 h-4 text-brand-400" />
                        <span>Customizing for <strong>{curAcc?.account_name}</strong> ({curAcc?.platform})</span>
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          const updated = { ...customCaptions };
                          delete updated[activeTab];
                          setCustomCaptions(updated);
                        }}
                        className="text-[10px] text-rose-400 hover:underline font-semibold"
                      >
                        Reset to Base Caption
                      </button>
                    </div>

                    {curAcc?.platform === 'youtube' && (
                      <div className="space-y-1">
                        <label className="text-xs font-semibold text-slate-300">
                          YouTube Video Title (Max 100 chars)
                        </label>
                        <input
                          type="text"
                          maxLength={100}
                          value={youtubeTitles[activeTab] || ''}
                          onChange={(e) => setYoutubeTitles({ ...youtubeTitles, [activeTab]: e.target.value })}
                          placeholder="Compelling video headline..."
                          className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        />
                      </div>
                    )}

                    <div className="space-y-1">
                      <label className="text-xs font-semibold text-slate-300">
                        {curAcc?.platform === 'youtube' ? 'Video Description (Max 5,000 chars)' : 'Target Caption'}
                      </label>
                      <textarea
                        rows={5}
                        value={curCustomVal}
                        onChange={(e) => setCustomCaptions({ ...customCaptions, [activeTab]: e.target.value })}
                        placeholder={`Custom caption for ${curAcc?.account_name}...`}
                        className="w-full rounded-xl bg-slate-950 border border-slate-800 p-3.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y leading-relaxed"
                      />
                    </div>
                  </div>
                );
              })()
            )}
          </div>

          {/* Section 3: Secure Media Uploader */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-md space-y-3">
            <label className="text-xs font-bold text-slate-300 uppercase tracking-wider block">
              3. Media Attachments
            </label>
            <MediaUploader
              media={media}
              onMediaChange={setMedia}
            />
          </div>

          {/* Section 4: Scheduling & Submission Toolbar */}
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-md space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <div className="flex items-center gap-2">
                <Calendar className="w-4 h-4 text-brand-400" />
                <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                  Scheduling Option
                </span>
              </div>
              <label className="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                <input
                  type="checkbox"
                  checked={isScheduled}
                  onChange={(e) => setIsScheduled(e.target.checked)}
                  className="rounded border-slate-700 bg-slate-950 text-brand-600 focus:ring-brand-500"
                />
                <span>Schedule for later</span>
              </label>
            </div>

            {isScheduled && (
              <div className="p-3.5 rounded-xl bg-slate-950 border border-slate-800 space-y-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                  <label className="text-xs font-semibold text-slate-300">
                    Select Publish Date & Time
                  </label>
                  <span className="text-[10px] px-2 py-0.5 rounded bg-brand-500/10 border border-brand-500/20 text-brand-400 font-mono">
                    Timezone: {activeWorkspace?.timezone || 'UTC'}
                  </span>
                </div>
                <input
                  type="datetime-local"
                  min={minScheduleTime}
                  onFocus={() => setMinScheduleTime(new Date().toISOString().slice(0, 16))}
                  value={scheduledAt}
                  onChange={(e) => setScheduledAt(e.target.value)}
                  className="w-full sm:w-auto rounded-xl bg-slate-900 border border-slate-700 px-3.5 py-2 text-xs text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
                {postId && (
                  <div className="pt-2 flex items-center justify-between text-xs border-t border-slate-800">
                    <span className="text-slate-400">Post #{postId} has a schedule</span>
                    <button
                      type="button"
                      disabled={submitting}
                      onClick={handleCancelSchedule}
                      className="px-2.5 py-1 rounded bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 text-amber-400 font-semibold transition"
                    >
                      Cancel Schedule (Revert to Draft)
                    </button>
                  </div>
                )}
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
              <button
                type="button"
                disabled={submitting}
                onClick={() => handleSubmit('draft')}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-750 border border-slate-700 text-xs font-semibold text-slate-200 transition disabled:opacity-50"
              >
                {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileText className="w-4 h-4 text-slate-400" />}
                <span>Save as Draft</span>
              </button>

              <div className="flex items-center gap-2">
                {isScheduled ? (
                  <button
                    type="button"
                    disabled={submitting}
                    onClick={() => handleSubmit('schedule')}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-brand-600 hover:from-indigo-500 hover:to-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-500/20 transition disabled:opacity-50"
                  >
                    {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Clock className="w-4 h-4" />}
                    <span>Schedule Post</span>
                  </button>
                ) : (
                  <button
                    type="button"
                    disabled={submitting}
                    onClick={() => handleSubmit('publish')}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-semibold shadow-lg shadow-brand-500/20 transition disabled:opacity-50"
                  >
                    {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                    <span>Publish Now</span>
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Right Column: Platform-Specific Live Previews (5 cols) */}
        <div className="lg:col-span-5 space-y-4 sticky top-20">
          <div className="p-4 rounded-2xl bg-slate-900 border border-slate-800 shadow-md space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Eye className="w-4 h-4 text-brand-400" />
                <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                  Platform Live Preview
                </span>
              </div>

              {/* Preview channel selector */}
              {selectedAccountIds.length > 1 && (
                <div className="flex items-center gap-1 bg-slate-950 p-1 rounded-lg border border-slate-800">
                  {selectedAccountIds.map(accId => {
                    const acc = accounts.find(a => a.id === accId);
                    if (!acc) return null;
                    return (
                      <button
                        key={accId}
                        type="button"
                        onClick={() => setPreviewAccountId(accId)}
                        title={acc.account_name}
                        className={`p-1.5 rounded-md text-xs transition ${
                          previewAccountId === accId
                            ? 'bg-slate-800 text-white shadow'
                            : 'text-slate-400 hover:text-white'
                        }`}
                      >
                        {PLATFORM_ICONS[acc.platform]}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Render Preview Component */}
            {activeAccount ? (
              <PlatformPreviews
                platform={activeAccount.platform}
                account={activeAccount}
                caption={baseCaption}
                customCaption={customCaptions[activeAccount.id]}
                youtubeTitle={youtubeTitles[activeAccount.id]}
                media={media}
              />
            ) : (
              <div className="p-8 rounded-xl bg-slate-950 border border-slate-800 text-center text-slate-500 space-y-2">
                <Eye className="w-8 h-8 mx-auto text-slate-600" />
                <p className="text-xs font-semibold text-slate-400">Select a connected social channel</p>
                <p className="text-[11px]">Previews will render in high-fidelity platform UI simulation.</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Saved Drafts Modal */}
      {showDraftsModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-2xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[80vh]">
            <div className="p-4 border-b border-slate-800 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <FolderOpen className="w-5 h-5 text-brand-400" />
                <h3 className="text-sm font-bold text-white">Saved Drafts ({draftsList.length})</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowDraftsModal(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-4 overflow-y-auto space-y-3 flex-1">
              {loadingDrafts ? (
                <div className="py-12 flex justify-center text-slate-500">
                  <Loader2 className="w-6 h-6 animate-spin text-brand-500" />
                </div>
              ) : !draftsList.length ? (
                <div className="py-12 text-center text-slate-500 space-y-2">
                  <FileText className="w-8 h-8 mx-auto text-slate-600" />
                  <p className="text-xs">No saved drafts found in this workspace.</p>
                </div>
              ) : (
                draftsList.map(draft => (
                  <div
                    key={draft.id}
                    className="p-3.5 rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-700 transition flex items-center justify-between gap-4"
                  >
                    <div className="space-y-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-mono font-bold text-brand-400">#{draft.id}</span>
                        <span className="text-[11px] text-slate-500">
                          {new Date(draft.created_at).toLocaleString()}
                        </span>
                      </div>
                      <p className="text-xs text-slate-300 line-clamp-1">
                        {draft.caption || <span className="italic text-slate-500">Untitled draft</span>}
                      </p>
                      <div className="flex items-center gap-2 text-[10px] text-slate-400">
                        <span>{draft.targets?.length || 0} targets</span>
                        <span>•</span>
                        <span>{draft.media?.length || 0} media</span>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => handleSelectDraft(draft)}
                      className="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shrink-0"
                    >
                      Load Draft
                    </button>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
