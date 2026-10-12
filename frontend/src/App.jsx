import React, { useState, useEffect } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import Header from './components/layout/Header';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import DashboardPage from './pages/DashboardPage';
import AccountsPage from './pages/AccountsPage';
import ComposerPage from './pages/ComposerPage';
import CalendarPage from './pages/CalendarPage';
import PostsPage from './pages/PostsPage';
import WorkspaceSettingsPage from './pages/WorkspaceSettingsPage';
import { 
  Share2, 
  Loader2 
} from 'lucide-react';

const VALID_TABS = ['dashboard', 'accounts', 'composer', 'calendar', 'posts', 'settings'];

function getInitialTab() {
  const path = window.location.pathname.replace(/^\/+/, '').split('/')[0].toLowerCase();
  if (VALID_TABS.includes(path)) {
    return path;
  }
  const hash = window.location.hash.replace(/^#/, '').toLowerCase();
  if (VALID_TABS.includes(hash)) {
    return hash;
  }
  return 'dashboard';
}

function MainApp() {
  const { user, loading } = useAuth();
  const [authView, setAuthView] = useState('login'); // 'login' | 'register' | 'forgot-password' | 'reset-password'
  const [resetData, setResetData] = useState({ email: '', token: '' });
  const [currentTab, setCurrentTab] = useState(getInitialTab);
  const [editingPost, setEditingPost] = useState(null);

  const handleSelectTab = (tab) => {
    setCurrentTab(tab);
    if (window.history.pushState) {
      window.history.pushState(null, '', `/${tab}`);
    }
  };

  useEffect(() => {
    const handlePopState = () => {
      setCurrentTab(getInitialTab());
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

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
      <Header currentTab={currentTab} onSelectTab={handleSelectTab} />

      <main className="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        {currentTab === 'dashboard' && <DashboardPage onNavigateTab={handleSelectTab} />}
        {currentTab === 'accounts' && <AccountsPage />}
        {currentTab === 'composer' && (
          <ComposerPage 
            initialPost={editingPost} 
            onNavigateTab={handleSelectTab} 
          />
        )}
        {currentTab === 'calendar' && (
          <CalendarPage 
            onNavigateTab={handleSelectTab} 
            onEditPost={(p) => {
              setEditingPost(p);
              handleSelectTab('composer');
            }} 
          />
        )}
        {currentTab === 'posts' && (
          <PostsPage 
            onNavigateTab={handleSelectTab} 
            onEditPost={(p) => {
              setEditingPost(p);
              handleSelectTab('composer');
            }} 
          />
        )}
        {currentTab === 'settings' && <WorkspaceSettingsPage />}
        {currentTab !== 'dashboard' && currentTab !== 'accounts' && currentTab !== 'composer' && currentTab !== 'calendar' && currentTab !== 'posts' && currentTab !== 'settings' && (
          <div className="p-12 rounded-2xl bg-slate-900 border border-slate-800 text-center space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-brand-500/10 border border-brand-500/20 text-brand-400 flex items-center justify-center mx-auto">
              <Share2 className="w-6 h-6" />
            </div>
            <h3 className="text-lg font-bold text-white capitalize">{currentTab}</h3>
            <p className="text-xs text-slate-400 max-w-md mx-auto">
              This module will be connected in subsequent milestones.
            </p>
          </div>
        )}
      </main>

      <footer className="border-t border-slate-800 bg-slate-900/40 py-6 text-center text-xs text-slate-500">
        SocialFlow SaaS — Milestone 5: Content Calendar & Scheduling Engine Active.
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
