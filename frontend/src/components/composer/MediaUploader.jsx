import React, { useState, useRef } from 'react';
import { apiRequest } from '../../api/client';
import { 
  Upload, 
  X, 
  Film, 
  Image as ImageIcon, 
  AlertCircle, 
  Loader2, 
  CheckCircle2, 
  FileCheck 
} from 'lucide-react';

export default function MediaUploader({ media = [], onMediaChange }) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState(null);
  const [dragActive, setDragActive] = useState(false);
  const fileInputRef = useRef(null);

  const allowedTypes = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/quicktime', 'video/webm'
  ];

  const handleFiles = async (fileList) => {
    setError(null);
    const files = Array.from(fileList);
    if (!files.length) return;

    for (const file of files) {
      if (!allowedTypes.includes(file.type)) {
        setError(`File "${file.name}" is not a supported format. Please upload JPG, PNG, WEBP, GIF, MP4, MOV, or WEBM.`);
        return;
      }
      const isVideo = file.type.startsWith('video/');
      const maxSize = isVideo ? 100 * 1024 * 1024 : 15 * 1024 * 1024;
      if (file.size > maxSize) {
        setError(`"${file.name}" exceeds the maximum allowed size of ${isVideo ? '100MB' : '15MB'}.`);
        return;
      }
    }

    setUploading(true);
    const uploadedItems = [];

    try {
      for (const file of files) {
        const formData = new FormData();
        formData.append('media_file', file);

        const res = await apiRequest('/api/media/upload', {
          method: 'POST',
          body: formData,
        });

        if (res.success && res.data) {
          uploadedItems.push(res.data);
        } else {
          throw new Error(res.message || `Failed to upload "${file.name}"`);
        }
      }

      onMediaChange([...media, ...uploadedItems]);
    } catch (err) {
      setError(err.message || 'Media upload failed. Please try again.');
    } finally {
      setUploading(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      handleFiles(e.dataTransfer.files);
    }
  };

  const handleDrag = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const removeMedia = (indexToRemove) => {
    onMediaChange(media.filter((_, idx) => idx !== indexToRemove));
  };

  const formatFileSize = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
  };

  return (
    <div className="space-y-4">
      {/* Upload Drop Zone */}
      <div
        onDragEnter={handleDrag}
        onDragOver={handleDrag}
        onDragLeave={handleDrag}
        onDrop={handleDrop}
        onClick={() => fileInputRef.current?.click()}
        className={`relative border-2 border-dashed rounded-2xl p-6 text-center cursor-pointer transition-all duration-200 ${
          dragActive 
            ? 'border-brand-500 bg-brand-500/10' 
            : 'border-slate-800 hover:border-slate-700 bg-slate-900/50 hover:bg-slate-900/80'
        } ${uploading ? 'opacity-50 pointer-events-none' : ''}`}
      >
        <input
          ref={fileInputRef}
          type="file"
          multiple
          accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.webm"
          className="hidden"
          onChange={(e) => handleFiles(e.target.files)}
        />

        <div className="flex flex-col items-center justify-center space-y-2">
          <div className="w-12 h-12 rounded-xl bg-slate-800 text-slate-300 flex items-center justify-center border border-slate-700">
            {uploading ? (
              <Loader2 className="w-6 h-6 animate-spin text-brand-400" />
            ) : (
              <Upload className="w-6 h-6 text-brand-400" />
            )}
          </div>
          <div className="text-sm">
            <span className="font-semibold text-white">Click to upload media</span>
            <span className="text-slate-400"> or drag and drop</span>
          </div>
          <p className="text-xs text-slate-500">
            Images (JPG, PNG, WEBP, GIF up to 15MB) • Videos (MP4, MOV, WEBM up to 100MB)
          </p>
        </div>
      </div>

      {error && (
        <div className="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs flex items-center gap-2">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Uploaded Media Previews */}
      {media.length > 0 && (
        <div className="space-y-2">
          <div className="flex items-center justify-between text-xs text-slate-400">
            <span>Attached Assets ({media.length})</span>
            <span className="text-[11px] text-slate-500">Validated with PHP Fileinfo</span>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {media.map((item, idx) => (
              <div 
                key={item.id || idx}
                className="group relative rounded-xl overflow-hidden bg-slate-900 border border-slate-800 aspect-video flex items-center justify-center"
              >
                {item.media_type === 'video' ? (
                  <div className="w-full h-full bg-slate-950 flex flex-col items-center justify-center text-slate-400 p-2">
                    <Film className="w-8 h-8 text-indigo-400 mb-1" />
                    <span className="text-[10px] text-slate-300 font-mono text-center truncate max-w-full">
                      {item.original_filename}
                    </span>
                    <span className="text-[9px] text-indigo-400 font-bold uppercase mt-0.5">
                      VIDEO • {formatFileSize(item.file_size_bytes)}
                    </span>
                  </div>
                ) : (
                  <img
                    src={item.preview_url}
                    alt={item.original_filename}
                    className="w-full h-full object-cover"
                  />
                )}

                {/* Overlay actions */}
                <div className="absolute inset-0 bg-slate-950/60 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      removeMedia(idx);
                    }}
                    className="p-1.5 rounded-lg bg-rose-500 hover:bg-rose-600 text-white transition shadow-md"
                    title="Remove attachment"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>

                {/* Badge */}
                <div className="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded bg-slate-950/80 backdrop-blur-xs text-[9px] font-semibold text-slate-300 border border-slate-700/50">
                  {item.media_type === 'video' ? 'MP4/MOV' : 'IMG'}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
