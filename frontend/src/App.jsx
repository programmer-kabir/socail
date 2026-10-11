import React, { useState } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import Header from './components/layout/Header';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import DashboardPage from './pages/DashboardPage';
import AccountsPage from './pages/AccountsPage';
import ComposerPage from './pages/ComposerPage';
import PostsPage from './pages/PostsPage';
import WorkspaceSettingsPage from './pages/WorkspaceSettingsPage';
import { 
  Building2, 
  Share2, 
  Calendar, 
  PenTool, 
  Layers, 
  Loader2 
} from 'lucide-react';

function MainApp() {
  const { user, loading } = useAuth();
  const [authView, setAuthView] = useState('login'); // 'login' | 'register' | 'forgot-password' | 'reset-password'
  const [resetData, setResetData] = useState({ email: '', token: '' });
  const [currentTab, setCurrentTab] = useState('dashboard'); // 'dashboard' | 'accounts' | 'composer' | 'calendar' | 'posts' | 'settings'
  const [editingPost, setEditingPost] = useState(null);

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-slate-400 space-y-3">
        <Loader2 className="w-8 h-8 animate-spin text-brand-500" />
        <p className="text-xs font-semibold tracking-wider uppercase">Loading SocialFlow...</p>
      </div>
    );
  }

  // Unauthenticated Flow
  if (!user) {
    if (authView === 'register') {
      return <RegisterPage onNavigate={(view) => setAuthView(view)} />;
    }
    if (authView === 'forgot-password') {
      return (
        <ForgotPasswordPage 
          onNavigate={(view, data) => {
            if (data) setResetData(data);
            setAuthView(view);
          }} 
        />
      );
    }
    if (authView === 'reset-password') {
      return (
        <ResetPasswordPage 
          initialEmail={resetData.email} 
          initialToken={resetData.token} 
          onNavigate={(view) => setAuthView(view)} 
        />
      );
    }
    return <LoginPage onNavigate={(view) => setAuthView(view)} />;
  }

  // Authenticated Workspace Experience
  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
      <Header currentTab={currentTab} onSelectTab={setCurrentTab} />

      <main className="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        {currentTab === 'dashboard' && <DashboardPage onNavigateTab={setCurrentTab} />}
        {currentTab === 'accounts' && <AccountsPage onNavigateTab={setCurrentTab} />}
        {currentTab === 'composer' && (
          <ComposerPage 
            initialPost={editingPost} 
            onNavigateTab={setCurrentTab} 
          />
        )}
        {currentTab === 'posts' && (
          <PostsPage 
            onNavigateTab={setCurrentTab} 
            onEditPost={(p) => {
              setEditingPost(p);
              setCurrentTab('composer');
            }} 
          />
        )}
        {currentTab === 'settings' && <WorkspaceSettingsPage />}
        {currentTab !== 'dashboard' && currentTab !== 'accounts' && currentTab !== 'composer' && currentTab !== 'posts' && currentTab !== 'settings' && (
          <div className="p-12 rounded-2xl bg-slate-900 border border-slate-800 text-center space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-brand-500/10 border border-brand-500/20 text-brand-400 flex items-center justify-center mx-auto">
              <Share2 className="w-6 h-6" />
            </div>
            <h3 className="text-lg font-bold text-white capitalize">{currentTab}</h3>
            <p className="text-xs text-slate-400 max-w-md mx-auto">
              This module will be connected in subsequent milestones. Complete multi-platform post composing, drafts, media uploads, and platform previews are fully functional.
            </p>
          </div>
        )}
      </main>

      <footer className="border-t border-slate-800 bg-slate-900/40 py-6 text-center text-xs text-slate-500">
        SocialFlow SaaS — Milestone 4: Multi-Platform Post Composer, Media Uploads & Previews Active.
      </footer>
    </div>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <MainApp />
    </AuthProvider>
  );
}
