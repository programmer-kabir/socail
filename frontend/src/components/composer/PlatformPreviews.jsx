import React from 'react';
import { 
  Heart, 
  MessageCircle, 
  Share2, 
  Bookmark, 
  Repeat, 
  Send, 
  ThumbsUp, 
  ThumbsDown, 
  Eye, 
  MoreHorizontal, 
  Globe, 
  Music2, 
  Sparkles, 
  AlertTriangle, 
  Film, 
  CheckCircle, 
  Info 
} from 'lucide-react';

export default function PlatformPreviews({ 
  platform, 
  account, 
  caption, 
  customCaption, 
  youtubeTitle,
  media = [] 
}) {
  const activeCaption = customCaption !== undefined && customCaption !== '' ? customCaption : caption;
  const isCustomized = customCaption !== undefined && customCaption !== '';

  // Validation rules check for preview banner
  const warnings = [];
  const charLength = (activeCaption || '').length;

  if (platform === 'twitter') {
    if (charLength > 280) {
      warnings.push(`Exceeds 280 character limit (${charLength}/280). Will be rejected upon publishing.`);
    }
  } else if (platform === 'instagram') {
    if (!media.length) {
      warnings.push('Instagram requires at least one image or video attachment.');
    }
    if (charLength > 2200) {
      warnings.push(`Exceeds 2,200 character limit (${charLength}/2,200).`);
    }
  } else if (platform === 'tiktok') {
    const hasVideo = media.some(m => m.media_type === 'video');
    if (!hasVideo) {
      warnings.push('TikTok requires a video file (MP4, MOV, or WEBM). Images cannot be posted.');
    }
    if (charLength > 2200) {
      warnings.push(`Exceeds 2,200 character limit (${charLength}/2,200).`);
    }
  } else if (platform === 'youtube') {
    const hasVideo = media.some(m => m.media_type === 'video');
    if (!hasVideo) {
      warnings.push('YouTube requires a video file attachment.');
    }
    if (charLength > 5000) {
      warnings.push(`Description exceeds 5,000 character limit (${charLength}/5,000).`);
    }
    if (youtubeTitle && youtubeTitle.length > 100) {
      warnings.push(`Title exceeds 100 character limit (${youtubeTitle.length}/100).`);
    }
  } else if (platform === 'facebook') {
    if (charLength > 63206) {
      warnings.push('Caption exceeds Facebook character limit.');
    }
  }

  const accountName = account?.account_name || `${platform ? platform.toUpperCase() : 'Channel'} Preview`;
  const username = account?.username || `${platform ? platform.toLowerCase() : 'user'}_handle`;

  return (
    <div className="space-y-4">
      {/* Simulation Watermark & Platform Status */}
      <div className="flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 text-xs">
        <div className="flex items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 font-medium">
            <Sparkles className="w-3 h-3" /> Simulated UI Preview
          </span>
          <span className="text-slate-400">
            {account?.is_sandbox ? 'Sandbox Channel' : 'Active Account'}
          </span>
        </div>
        {isCustomized && (
          <span className="text-[11px] font-semibold text-brand-400 bg-brand-500/10 px-2 py-0.5 rounded border border-brand-500/20">
            Custom Platform Caption
          </span>
        )}
      </div>

      {/* Warnings & Incompatibility Alert */}
      {warnings.length > 0 && (
        <div className="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs space-y-1">
          <div className="flex items-center gap-1.5 font-bold">
            <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0" />
            <span>Platform Compatibility Warning</span>
          </div>
          <ul className="list-disc list-inside space-y-0.5 text-[11px] text-amber-200/90 pl-1">
            {warnings.map((w, i) => (
              <li key={i}>{w}</li>
            ))}
          </ul>
        </div>
      )}

      {/* Platform Mockup Container */}
      <div className="rounded-2xl border border-slate-800 bg-slate-950 p-4 shadow-2xl overflow-hidden">
        {platform === 'facebook' && (
          <FacebookPreview 
            accountName={accountName} 
            caption={activeCaption} 
            media={media} 
          />
        )}

        {platform === 'instagram' && (
          <InstagramPreview 
            username={username} 
            caption={activeCaption} 
            media={media} 
          />
        )}

        {platform === 'twitter' && (
          <TwitterPreview 
            accountName={accountName} 
            username={username} 
            caption={activeCaption} 
            media={media} 
          />
        )}

        {platform === 'tiktok' && (
          <TikTokPreview 
            username={username} 
            caption={activeCaption} 
            media={media} 
          />
        )}

        {platform === 'youtube' && (
          <YouTubePreview 
            channelName={accountName} 
            title={youtubeTitle || (activeCaption ? activeCaption.split('\n')[0].substring(0, 70) : 'Untitled Video')} 
            description={activeCaption} 
            media={media} 
          />
        )}
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// FACEBOOK PAGES PREVIEW
// -------------------------------------------------------------
function FacebookPreview({ accountName, caption, media }) {
  return (
    <div className="bg-[#242526] rounded-xl text-[#e4e6eb] font-sans border border-[#393a3b] max-w-lg mx-auto shadow-md">
      {/* Header */}
      <div className="p-3.5 flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <div className="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center font-bold text-white shadow">
            {accountName.charAt(0)}
          </div>
          <div>
            <div className="font-semibold text-sm hover:underline cursor-pointer">{accountName}</div>
            <div className="text-xs text-[#b0b3b8] flex items-center gap-1">
              <span>Just now</span>
              <span>•</span>
              <Globe className="w-3 h-3 text-[#b0b3b8]" />
            </div>
          </div>
        </div>
        <button className="text-[#b0b3b8] hover:text-white p-1">
          <MoreHorizontal className="w-5 h-5" />
        </button>
      </div>

      {/* Caption Body */}
      <div className="px-3.5 pb-3 text-sm whitespace-pre-wrap leading-relaxed">
        {caption || <span className="text-slate-500 italic">Write a caption to see it previewed here...</span>}
      </div>

      {/* Media Box */}
      {media.length > 0 && (
        <div className="bg-[#18191a] border-y border-[#393a3b] overflow-hidden">
          {media[0].media_type === 'video' ? (
            <div className="relative aspect-video bg-black flex flex-col items-center justify-center text-slate-300">
              <Film className="w-12 h-12 text-blue-400 mb-2" />
              <span className="text-xs font-semibold">{media[0].original_filename}</span>
              <span className="text-[10px] text-slate-400 mt-1">Facebook Video Player Simulation</span>
            </div>
          ) : (
            <img 
              src={media[0].preview_url} 
              alt="Facebook attachment" 
              className="w-full max-h-96 object-cover" 
            />
          )}
          {media.length > 1 && (
            <div className="p-2 text-center text-xs text-[#b0b3b8] bg-[#242526] border-t border-[#393a3b]">
              + {media.length - 1} more media items in album
            </div>
          )}
        </div>
      )}

      {/* Engagement Stats */}
      <div className="px-3.5 py-2 flex items-center justify-between text-xs text-[#b0b3b8] border-b border-[#393a3b]">
        <div className="flex items-center gap-1.5">
          <span className="w-4 h-4 rounded-full bg-blue-500 text-white flex items-center justify-center text-[9px] font-bold">
            👍
          </span>
          <span>12</span>
        </div>
        <div className="flex gap-3">
          <span>3 comments</span>
          <span>1 share</span>
        </div>
      </div>

      {/* Action Buttons */}
      <div className="px-2 py-1.5 grid grid-cols-3 text-xs font-semibold text-[#b0b3b8]">
        <button className="flex items-center justify-center gap-2 py-1.5 rounded-lg hover:bg-[#3a3b3c] transition">
          <ThumbsUp className="w-4 h-4" /> Like
        </button>
        <button className="flex items-center justify-center gap-2 py-1.5 rounded-lg hover:bg-[#3a3b3c] transition">
          <MessageCircle className="w-4 h-4" /> Comment
        </button>
        <button className="flex items-center justify-center gap-2 py-1.5 rounded-lg hover:bg-[#3a3b3c] transition">
          <Share2 className="w-4 h-4" /> Share
        </button>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// INSTAGRAM POST PREVIEW
// -------------------------------------------------------------
function InstagramPreview({ username, caption, media }) {
  return (
    <div className="bg-black text-white rounded-xl font-sans border border-neutral-800 max-w-sm mx-auto shadow-xl">
      {/* Header */}
      <div className="p-3 flex items-center justify-between border-b border-neutral-900">
        <div className="flex items-center gap-2.5">
          <div className="p-[2px] rounded-full bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600">
            <div className="w-7 h-7 rounded-full bg-black border-2 border-black flex items-center justify-center text-xs font-bold uppercase">
              {username.charAt(0)}
            </div>
          </div>
          <div>
            <div className="text-xs font-semibold">{username}</div>
            <div className="text-[10px] text-neutral-400">Original audio</div>
          </div>
        </div>
        <MoreHorizontal className="w-4 h-4 text-neutral-400" />
      </div>

      {/* Media Viewport */}
      <div className="aspect-square bg-neutral-950 flex items-center justify-center overflow-hidden border-y border-neutral-900">
        {media.length > 0 ? (
          media[0].media_type === 'video' ? (
            <div className="w-full h-full bg-neutral-900 flex flex-col items-center justify-center text-neutral-400 p-4 text-center">
              <Film className="w-10 h-10 text-pink-500 mb-2" />
              <span className="text-xs font-medium text-neutral-200">{media[0].original_filename}</span>
              <span className="text-[10px] text-neutral-500 mt-1">Instagram Video/Reel Preview</span>
            </div>
          ) : (
            <img 
              src={media[0].preview_url} 
              alt="Instagram asset" 
              className="w-full h-full object-cover" 
            />
          )
        ) : (
          <div className="text-center text-neutral-600 p-6 space-y-2">
            <div className="w-12 h-12 rounded-full border border-dashed border-neutral-700 mx-auto flex items-center justify-center">
              📷
            </div>
            <span className="text-xs block">Media is required for Instagram</span>
          </div>
        )}
      </div>

      {/* Action Bar */}
      <div className="p-3 space-y-2">
        <div className="flex items-center justify-between text-neutral-200">
          <div className="flex items-center gap-4">
            <Heart className="w-5 h-5 hover:text-rose-500 cursor-pointer" />
            <MessageCircle className="w-5 h-5 hover:text-neutral-400 cursor-pointer" />
            <Send className="w-5 h-5 hover:text-neutral-400 cursor-pointer" />
          </div>
          <Bookmark className="w-5 h-5 hover:text-neutral-400 cursor-pointer" />
        </div>

        <div className="text-xs font-semibold">1,428 likes</div>

        {/* Caption */}
        <div className="text-xs leading-relaxed text-neutral-200">
          <span className="font-semibold mr-1.5 text-white">{username}</span>
          <span className="whitespace-pre-wrap">
            {caption || <span className="text-neutral-600 italic">Caption preview here...</span>}
          </span>
        </div>

        <div className="text-[10px] uppercase tracking-wider text-neutral-500 pt-1">
          Just now • Instagram Graph API
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// X (TWITTER) PREVIEW
// -------------------------------------------------------------
function TwitterPreview({ accountName, username, caption, media }) {
  return (
    <div className="bg-black text-white rounded-xl font-sans border border-neutral-800 max-w-lg mx-auto p-4 space-y-3 shadow-md">
      {/* Top author row */}
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-neutral-800 border border-neutral-700 flex items-center justify-center font-bold text-sm">
            {accountName.charAt(0)}
          </div>
          <div>
            <div className="flex items-center gap-1.5">
              <span className="font-bold text-sm text-white">{accountName}</span>
              <span className="text-xs text-neutral-500">@{username}</span>
              <span className="text-neutral-500 text-xs">·</span>
              <span className="text-xs text-neutral-500">Just now</span>
            </div>
          </div>
        </div>
        <div className="w-4 h-4 text-neutral-400">𝕏</div>
      </div>

      {/* Tweet Body */}
      <div className="text-sm text-neutral-100 whitespace-pre-wrap leading-normal pl-13">
        {caption || <span className="text-neutral-600 italic">What is happening?! (Caption preview)</span>}
      </div>

      {/* Tweet Media */}
      {media.length > 0 && (
        <div className="rounded-2xl overflow-hidden border border-neutral-800 max-h-72 bg-neutral-900">
          {media[0].media_type === 'video' ? (
            <div className="aspect-video bg-neutral-950 flex flex-col items-center justify-center text-neutral-400 p-4">
              <Film className="w-10 h-10 text-sky-400 mb-2" />
              <span className="text-xs font-semibold">{media[0].original_filename}</span>
              <span className="text-[10px] text-neutral-500 mt-1">X Video Player</span>
            </div>
          ) : (
            <img 
              src={media[0].preview_url} 
              alt="X asset" 
              className="w-full h-full object-cover max-h-72" 
            />
          )}
        </div>
      )}

      {/* Action Row */}
      <div className="flex items-center justify-between text-neutral-500 text-xs pt-1 px-2 border-t border-neutral-900">
        <div className="flex items-center gap-1 hover:text-sky-400 cursor-pointer">
          <MessageCircle className="w-4 h-4" /> <span>4</span>
        </div>
        <div className="flex items-center gap-1 hover:text-green-400 cursor-pointer">
          <Repeat className="w-4 h-4" /> <span>12</span>
        </div>
        <div className="flex items-center gap-1 hover:text-rose-400 cursor-pointer">
          <Heart className="w-4 h-4" /> <span>89</span>
        </div>
        <div className="flex items-center gap-1 hover:text-sky-400 cursor-pointer">
          <Eye className="w-4 h-4" /> <span>1.2K</span>
        </div>
        <div className="flex items-center gap-1 hover:text-sky-400 cursor-pointer">
          <Share2 className="w-4 h-4" />
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// TIKTOK PREVIEW (VERTICAL MOBILE FRAME)
// -------------------------------------------------------------
function TikTokPreview({ username, caption, media }) {
  const hasVideo = media.some(m => m.media_type === 'video');

  return (
    <div className="relative w-72 h-[480px] mx-auto rounded-3xl overflow-hidden bg-black border-4 border-neutral-800 shadow-2xl flex flex-col justify-between">
      {/* Video Background / Placeholder */}
      <div className="absolute inset-0 bg-gradient-to-b from-neutral-900 via-neutral-950 to-black flex items-center justify-center">
        {hasVideo ? (
          <div className="text-center p-4">
            <Film className="w-14 h-14 text-rose-500 mx-auto mb-2 animate-pulse" />
            <span className="text-xs font-semibold text-white block">Video Ready for TikTok</span>
            <span className="text-[10px] text-neutral-400 mt-1 block font-mono">
              {media.find(m => m.media_type === 'video')?.original_filename}
            </span>
          </div>
        ) : (
          <div className="text-center p-4 text-neutral-500 space-y-2">
            <Film className="w-12 h-12 mx-auto text-neutral-700" />
            <p className="text-xs font-medium text-rose-400">Video Attachment Required</p>
            <p className="text-[10px] text-neutral-600">TikTok Direct Publishing only supports video formats.</p>
          </div>
        )}
      </div>

      {/* Top Bar */}
      <div className="relative z-10 p-3 flex justify-between items-center text-white/80 text-xs">
        <span className="text-[10px] font-bold tracking-widest text-white/60">LIVE</span>
        <div className="flex gap-4 font-bold">
          <span className="text-white/60">Following</span>
          <span className="text-white border-b-2 border-white pb-0.5">For You</span>
        </div>
        <span className="text-xs">🔍</span>
      </div>

      {/* Right Action Bar */}
      <div className="relative z-10 self-end mr-3 flex flex-col items-center gap-4 text-white">
        <div className="relative">
          <div className="w-10 h-10 rounded-full border-2 border-white bg-neutral-800 flex items-center justify-center font-bold text-xs uppercase">
            {username.charAt(0)}
          </div>
          <div className="absolute -bottom-1 left-1/2 -translate-x-1/2 w-4 h-4 rounded-full bg-rose-500 text-white flex items-center justify-center text-[10px]">
            +
          </div>
        </div>

        <div className="flex flex-col items-center">
          <Heart className="w-7 h-7 fill-white/80" />
          <span className="text-[10px] font-semibold mt-0.5">42.8K</span>
        </div>

        <div className="flex flex-col items-center">
          <MessageCircle className="w-7 h-7 fill-white/80" />
          <span className="text-[10px] font-semibold mt-0.5">1,204</span>
        </div>

        <div className="flex flex-col items-center">
          <Bookmark className="w-7 h-7 fill-white/80" />
          <span className="text-[10px] font-semibold mt-0.5">859</span>
        </div>

        <div className="flex flex-col items-center">
          <Share2 className="w-7 h-7 fill-white/80" />
          <span className="text-[10px] font-semibold mt-0.5">340</span>
        </div>

        <div className="w-8 h-8 rounded-full bg-neutral-900 border border-neutral-700 flex items-center justify-center animate-spin">
          <Music2 className="w-4 h-4 text-rose-400" />
        </div>
      </div>

      {/* Bottom Info Overlay */}
      <div className="relative z-10 p-3 bg-gradient-to-t from-black via-black/80 to-transparent text-white space-y-1.5">
        <div className="font-bold text-sm">@{username}</div>
        <p className="text-xs line-clamp-2 text-white/90">
          {caption || 'Add your caption with trending #hashtags...'}
        </p>
        <div className="flex items-center gap-1.5 text-[10px] text-white/70">
          <Music2 className="w-3 h-3" />
          <span className="truncate">Original Sound - {username}</span>
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// YOUTUBE VIDEO PREVIEW
// -------------------------------------------------------------
function YouTubePreview({ channelName, title, description, media }) {
  const hasVideo = media.some(m => m.media_type === 'video');

  return (
    <div className="bg-[#0f0f0f] text-white rounded-xl font-sans border border-neutral-800 max-w-lg mx-auto overflow-hidden shadow-xl">
      {/* 16:9 Video Viewport */}
      <div className="aspect-video bg-black flex items-center justify-center relative border-b border-neutral-800">
        {hasVideo ? (
          <div className="text-center p-4">
            <Film className="w-14 h-14 text-red-500 mx-auto mb-2" />
            <span className="text-sm font-bold text-white block">YouTube Video Player Preview</span>
            <span className="text-xs text-neutral-400 mt-1 block font-mono">
              {media.find(m => m.media_type === 'video')?.original_filename}
            </span>
          </div>
        ) : (
          <div className="text-center p-4 text-neutral-500 space-y-2">
            <Film className="w-12 h-12 mx-auto text-neutral-700" />
            <p className="text-xs font-semibold text-red-400">Video File Required for YouTube</p>
            <p className="text-[10px] text-neutral-600">YouTube Data API requires an MP4 or MOV video file.</p>
          </div>
        )}

        {/* Video Duration Badge */}
        <div className="absolute bottom-2 right-2 px-1.5 py-0.5 rounded bg-black/80 text-[10px] font-bold font-mono text-white">
          02:45
        </div>
      </div>

      {/* Video Metadata */}
      <div className="p-3.5 space-y-3">
        {/* Title */}
        <h3 className="font-bold text-base leading-snug text-neutral-100">
          {title || <span className="text-neutral-600 italic">Enter Video Title</span>}
        </h3>

        {/* Channel Row */}
        <div className="flex items-center justify-between pb-2 border-b border-neutral-800">
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-full bg-red-600 flex items-center justify-center font-bold text-sm">
              {channelName.charAt(0)}
            </div>
            <div>
              <div className="text-xs font-bold">{channelName}</div>
              <div className="text-[10px] text-neutral-400">12.5K subscribers</div>
            </div>
          </div>
          <button className="px-3.5 py-1.5 rounded-full bg-white text-black font-semibold text-xs hover:bg-neutral-200 transition">
            Subscribe
          </button>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2 overflow-x-auto text-xs pb-1">
          <div className="flex items-center rounded-full bg-neutral-800 hover:bg-neutral-700 transition">
            <button className="flex items-center gap-1.5 px-3 py-1.5 border-r border-neutral-700">
              <ThumbsUp className="w-3.5 h-3.5" /> <span>3.4K</span>
            </button>
            <button className="px-2.5 py-1.5">
              <ThumbsDown className="w-3.5 h-3.5" />
            </button>
          </div>
          <button className="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-neutral-800 hover:bg-neutral-700 transition">
            <Share2 className="w-3.5 h-3.5" /> Share
          </button>
        </div>

        {/* Description Box */}
        <div className="p-2.5 rounded-xl bg-neutral-900 border border-neutral-800 text-xs space-y-1">
          <div className="font-bold text-neutral-300 text-[11px]">
            24,912 views • Just now
          </div>
          <p className="text-neutral-300 whitespace-pre-wrap leading-relaxed">
            {description || <span className="text-neutral-600 italic">Video description will be shown here...</span>}
          </p>
        </div>
      </div>
    </div>
  );
}
