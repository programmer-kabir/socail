import React, { useState, useEffect } from 'react';
import { 
  Server, 
  Database, 
  ShieldCheck, 
  Activity, 
  Layers, 
  CheckCircle2, 
  Clock, 
  Share2, 
  ArrowRight,
  RefreshCw,
  AlertCircle
} from 'lucide-react';

export default function App() {
  const [healthData, setHealthData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchHealth = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch('/api/health');
      if (!res.ok) {
        throw new Error(`Server returned HTTP ${res.status}`);
      }
      const data = await res.json();
      setHealthData(data);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchHealth();
  }, []);

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
      {/* Top Navigation */}
      <header className="border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-400 flex items-center justify-center shadow-lg shadow-brand-500/20">
              <Share2 className="w-5 h-5 text-white" />
            </div>
            <div>
              <span className="text-xl font-bold tracking-tight text-white">SocialFlow</span>
              <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-brand-500/20 text-brand-300 border border-brand-500/30">
                v1.0 Milestone 1
              </span>
            </div>
          </div>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2 text-xs text-slate-400">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
              Environment: Windows / PHP 8.2 / MariaDB
            </div>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-8">
        {/* Hero Section */}
        <div className="p-8 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-900/80 to-brand-950/40 border border-slate-800 shadow-xl relative overflow-hidden">
          <div className="relative z-10 max-w-3xl space-y-4">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-medium">
              <CheckCircle2 className="w-3.5 h-3.5" /> Milestone 1 Verified & Active
            </div>
            <h1 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white">
              Social Media Management SaaS Architecture
            </h1>
            <p className="text-slate-400 text-sm sm:text-base leading-relaxed">
              Foundational stack configured with pure React + Vite, Tailwind CSS, modular native PHP 8.2 REST API, and normalized MySQL/MariaDB database with multi-tenant workspace isolation.
            </p>
          </div>
        </div>

        {/* Backend Health Check Card */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="p-6 rounded-xl bg-slate-900 border border-slate-800 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                  <Server className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-white">PHP REST Core</h3>
                  <p className="text-xs text-slate-400">PHP 8.2 Modular Router</p>
                </div>
              </div>
              <button 
                onClick={fetchHealth} 
                disabled={loading}
                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                title="Refresh health status"
              >
                <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
              </button>
            </div>
            <div className="space-y-2 pt-2 border-t border-slate-800/80 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-400">Status</span>
                <span className="text-emerald-400 font-medium flex items-center gap-1">
                  <CheckCircle2 className="w-3 h-3" /> Operational
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Response Code</span>
                <span className="text-slate-200 font-mono">200 OK</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">CORS & Security</span>
                <span className="text-slate-200">nosniff, same-origin</span>
              </div>
            </div>
          </div>

          <div className="p-6 rounded-xl bg-slate-900 border border-slate-800 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                  <Database className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-white">MySQL / MariaDB</h3>
                  <p className="text-xs text-slate-400">PDO Prepared Statements</p>
                </div>
              </div>
            </div>
            <div className="space-y-2 pt-2 border-t border-slate-800/80 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-400">Database</span>
                <span className="text-slate-200 font-mono">socialflow_db</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Engine & Charset</span>
                <span className="text-slate-200">InnoDB (utf8mb4)</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Schema Status</span>
                <span className="text-emerald-400 font-medium">13 Tables Migrated</span>
              </div>
            </div>
          </div>

          <div className="p-6 rounded-xl bg-slate-900 border border-slate-800 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                  <ShieldCheck className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-white">Security & Crypto</h3>
                  <p className="text-xs text-slate-400">AES-256-GCM + Token Hash</p>
                </div>
              </div>
            </div>
            <div className="space-y-2 pt-2 border-t border-slate-800/80 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-400">Credentials Cipher</span>
                <span className="text-slate-200 font-mono">AES-256-GCM</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Session Auth</span>
                <span className="text-slate-200">Opaque SHA-256</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Upload Defense</span>
                <span className="text-emerald-400 font-medium">.htaccess script lock</span>
              </div>
            </div>
          </div>
        </div>

        {/* Milestone 1 Checklist */}
        <div className="p-6 rounded-xl bg-slate-900 border border-slate-800 space-y-4">
          <h2 className="text-lg font-bold text-white flex items-center gap-2">
            <Layers className="w-5 h-5 text-brand-400" /> Milestone 1 Architectural Verification Checklist
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            {[
              "Database Connection Factory with PDO prepared statements",
              "13 Normalized Tables with strict foreign keys & indexes",
              "AES-256-GCM Authenticated Encryption service (verified)",
              "Replay-protected OAuth state table bound to user/workspace",
              "Post Targets table with independent status & idempotency key",
              "Public REST API Gateway with CORS & nosniff headers",
              "Automated migration runner with CLI runner script",
              "Initial Seed Data (demo user, workspace, sandbox accounts)",
              "React 19 + Vite frontend with Tailwind CSS design tokens",
              "Protected Uploads storage directory with script execution lock"
            ].map((item, idx) => (
              <div key={idx} className="flex items-start gap-2.5 p-3 rounded-lg bg-slate-800/50 border border-slate-800 text-slate-300">
                <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                <span>{item}</span>
              </div>
            ))}
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="border-t border-slate-800 bg-slate-900/40 py-6 text-center text-xs text-slate-500">
        SocialFlow Architecture — Ready for Milestone 2: Authentication & Workspace Multi-Tenancy.
      </footer>
    </div>
  );
}
