import React, { useEffect, useState, useRef } from 'react';
import { apiRequest } from '../api/client';
import { CheckCircle2, AlertCircle, Loader2, Share2 } from 'lucide-react';

/**
 * OAuthCallbackPage
 *
 * Rendered when the URL path is /accounts/callback.
 * Captures `code` and `state` from the query string (set by Facebook),
 * sends them to the backend for token exchange, then navigates to Accounts.
 *
 * Security notes:
 *  - No App Secret is present in frontend code.
 *  - The `state` token is validated server-side against the stored hash.
 *  - Access tokens are never logged or rendered.
 *  - `redirect_uri` sent here must exactly match what was used during
 *    authorization URL generation (window.location.origin + '/accounts/callback').
 */
export default function OAuthCallbackPage({ onNavigate }) {
  const [phase, setPhase] = useState('processing'); // 'processing' | 'success' | 'error'
  const [message, setMessage] = useState('Exchanging authorization code with Facebook…');
  const [accountName, setAccountName] = useState(null);
  const ran = useRef(false);

  useEffect(() => {
    // StrictMode guard — prevent double-execution in development
    if (ran.current) return;
    ran.current = true;

    const params = new URLSearchParams(window.location.search);
    const code  = params.get('code');
    const state = params.get('state');
    const fbError = params.get('error');
    const fbErrorDesc = params.get('error_description');

    // Facebook denied or user cancelled
    if (fbError) {
      setPhase('error');
      setMessage(fbErrorDesc
        ? decodeURIComponent(fbErrorDesc)
        : `Facebook returned an error: ${fbError}`);
      return;
    }

    if (!code || !state) {
      setPhase('error');
      setMessage('Missing authorization code or state. Please try connecting again.');
      return;
    }

    const platform    = sessionStorage.getItem('oauth_platform') || 'facebook';
    const redirectUri = `${window.location.origin}/accounts/callback`;

    const exchange = async () => {
      try {
        const res = await apiRequest('/api/accounts/oauth/callback', {
          method: 'POST',
          body: JSON.stringify({ platform, code, state, redirect_uri: redirectUri }),
        });

        if (res.success) {
          sessionStorage.removeItem('oauth_platform');
          setAccountName(res.data?.account_name ?? null);
          setPhase('success');
          setMessage(res.message || `Connected ${res.data?.account_name ?? platform} successfully.`);
          // Redirect to accounts tab after a brief confirmation pause
          setTimeout(() => onNavigate('accounts'), 2800);
        } else {
          setPhase('error');
          setMessage(res.message || 'Authorization failed. Please try again.');
        }
      } catch (err) {
        setPhase('error');
        setMessage(err.message || 'An unexpected error occurred during authorization.');
      }
    };

    exchange();
  }, []);

  return (
    <div className="min-h-screen bg-slate-950 flex flex-col items-center justify-center p-6">
      {/* Logo mark */}
      <div className="mb-8 flex items-center gap-2.5 text-white">
        <div className="w-8 h-8 rounded-xl bg-brand-600 flex items-center justify-center shadow-lg shadow-brand-600/30">
          <Share2 className="w-4 h-4 text-white" />
        </div>
        <span className="font-bold text-lg tracking-tight">SocialFlow</span>
      </div>

      <div className="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-8 space-y-6 text-center">

        {/* Processing */}
        {phase === 'processing' && (
          <>
            <div className="w-16 h-16 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center mx-auto">
              <Loader2 className="w-8 h-8 text-brand-400 animate-spin" />
            </div>
            <div className="space-y-1.5">
              <h1 className="text-lg font-bold text-white">Completing Authorization</h1>
              <p className="text-sm text-slate-400">{message}</p>
            </div>
            <div className="flex justify-center gap-1.5 pt-2">
              {[0, 1, 2].map(i => (
                <span
                  key={i}
                  className="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"
                  style={{ animationDelay: `${i * 200}ms` }}
                />
              ))}
            </div>
          </>
        )}

        {/* Success */}
        {phase === 'success' && (
          <>
            <div className="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto">
              <CheckCircle2 className="w-8 h-8 text-emerald-400" />
            </div>
            <div className="space-y-1.5">
              <h1 className="text-lg font-bold text-white">Connected!</h1>
              {accountName && (
                <p className="text-base font-semibold text-emerald-400">{accountName}</p>
              )}
              <p className="text-sm text-slate-400">{message}</p>
            </div>
            <p className="text-xs text-slate-500">Redirecting to your accounts…</p>
          </>
        )}

        {/* Error */}
        {phase === 'error' && (
          <>
            <div className="w-16 h-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center mx-auto">
              <AlertCircle className="w-8 h-8 text-rose-400" />
            </div>
            <div className="space-y-1.5">
              <h1 className="text-lg font-bold text-white">Authorization Failed</h1>
              <p className="text-sm text-rose-300 leading-relaxed">{message}</p>
            </div>
            <button
              onClick={() => onNavigate('accounts')}
              className="w-full py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold border border-slate-700 transition mt-2"
            >
              Back to Accounts
            </button>
          </>
        )}
      </div>

      <p className="mt-6 text-xs text-slate-600">
        Your credentials are handled securely on the server. No tokens are stored in the browser.
      </p>
    </div>
  );
}
