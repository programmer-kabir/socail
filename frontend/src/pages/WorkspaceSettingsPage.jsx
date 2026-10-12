import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { apiRequest } from '../api/client';
import { 
  Building2, 
  Users, 
  ShieldCheck, 
  Shield, 
  Save, 
  CheckCircle2, 
  AlertCircle, 
  Loader2 
} from 'lucide-react';

export default function WorkspaceSettingsPage() {
  const { activeWorkspace, updateActiveWorkspace } = useAuth();
  const [name, setName] = useState(activeWorkspace?.name || '');
  const [timezone, setTimezone] = useState(activeWorkspace?.timezone || 'UTC');
  const [members, setMembers] = useState([]);
  const [loadingMembers, setLoadingMembers] = useState(true);
  const [saving, setSaving] = useState(false);
  const [successMsg, setSuccessMsg] = useState(null);
  const [errorMsg, setErrorMsg] = useState(null);

  const isAuthorizedToEdit = activeWorkspace?.role === 'owner' || activeWorkspace?.role === 'admin';

  const fetchMembers = async () => {
    setLoadingMembers(true);
    try {
      const res = await apiRequest('/api/workspaces/current');
      if (res.success && res.data) {
        setMembers(res.data.members || []);
      }
    } catch (err) {
      console.error('Failed to load workspace members', err);
    } finally {
      setLoadingMembers(false);
    }
  };

  useEffect(() => {
    if (activeWorkspace) {
      setName(activeWorkspace.name);
      setTimezone(activeWorkspace.timezone || 'UTC');
      fetchMembers();
    }
  }, [activeWorkspace]);

  const handleSaveSettings = async (e) => {
    e.preventDefault();
    if (!isAuthorizedToEdit) return;

    setSaving(true);
    setErrorMsg(null);
    setSuccessMsg(null);

    try {
      await updateActiveWorkspace(name, timezone);
      setSuccessMsg('Workspace settings saved successfully.');
      setTimeout(() => setSuccessMsg(null), 4000);
    } catch (err) {
      setErrorMsg(err.message || 'Failed to update workspace settings.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-8 max-w-5xl mx-auto">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
          <Building2 className="w-6 h-6 text-brand-400" /> Workspace Settings
        </h1>
        <p className="text-sm text-slate-400 mt-1">
          Manage workspace details, localization timezone, and team memberships.
        </p>
      </div>

      {successMsg && (
        <div className="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4 shrink-0" />
          <span>{successMsg}</span>
        </div>
      )}

      {errorMsg && (
        <div className="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm flex items-center gap-2">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{errorMsg}</span>
        </div>
      )}

      {/* General Settings Card */}
      <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-6">
        <div className="flex items-center justify-between pb-4 border-b border-slate-800">
          <div>
            <h2 className="text-base font-semibold text-white">General Information</h2>
            <p className="text-xs text-slate-400">Configure workspace name and primary publishing timezone.</p>
          </div>
          <span className="capitalize px-2.5 py-1 rounded-full text-xs font-semibold bg-brand-500/10 text-brand-300 border border-brand-500/20">
            Role: {activeWorkspace?.role}
          </span>
        </div>

        <form onSubmit={handleSaveSettings} className="space-y-5">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">
                Workspace Name
              </label>
              <input
                type="text"
                required
                disabled={!isAuthorizedToEdit}
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white placeholder-slate-500 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition disabled:opacity-60"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">
                Default Publishing Timezone
              </label>
              <select
                disabled={!isAuthorizedToEdit}
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition disabled:opacity-60"
              >
                <option value="UTC">UTC (Universal Time)</option>
                <option value="America/New_York">Eastern Time (US & Canada)</option>
                <option value="America/Chicago">Central Time (US & Canada)</option>
                <option value="America/Denver">Mountain Time (US & Canada)</option>
                <option value="America/Los_Angeles">Pacific Time (US & Canada)</option>
                <option value="Europe/London">London (GMT / BST)</option>
                <option value="Europe/Paris">Paris, Berlin, Rome (CET)</option>
                <option value="Asia/Tokyo">Tokyo (JST)</option>
                <option value="Asia/Dubai">Dubai (GST)</option>
                <option value="Asia/Dhaka">Dhaka (BST)</option>
                <option value="Australia/Sydney">Sydney (AEST)</option>
              </select>
            </div>
          </div>

          {isAuthorizedToEdit ? (
            <div className="flex justify-end pt-2">
              <button
                type="submit"
                disabled={saving}
                className="py-2 px-5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-lg shadow-brand-600/30 flex items-center gap-2 transition disabled:opacity-50"
              >
                {saving ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" /> Saving...
                  </>
                ) : (
                  <>
                    <Save className="w-3.5 h-3.5" /> Save Changes
                  </>
                )}
              </button>
            </div>
          ) : (
            <p className="text-xs text-amber-400 bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/20">
              Only workspace Owners and Admins have permission to modify settings.
            </p>
          )}
        </form>
      </div>

      {/* Team Members Card */}
      <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-4">
        <div className="flex items-center justify-between pb-4 border-b border-slate-800">
          <div>
            <h2 className="text-base font-semibold text-white flex items-center gap-2">
              <Users className="w-4 h-4 text-brand-400" /> Workspace Members
            </h2>
            <p className="text-xs text-slate-400">Team members with authorized access to this workspace.</p>
          </div>
          <span className="text-xs text-slate-400 font-medium">
            {members.length} {members.length === 1 ? 'member' : 'members'}
          </span>
        </div>

        {loadingMembers ? (
          <div className="py-8 flex justify-center text-slate-500">
            <Loader2 className="w-6 h-6 animate-spin" />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400 uppercase tracking-wider font-semibold">
                  <th className="py-3 px-4">Member</th>
                  <th className="py-3 px-4">Role</th>
                  <th className="py-3 px-4">Joined At</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {members.map((m) => (
                  <tr key={m.id} className="hover:bg-slate-800/30 transition">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white font-bold text-xs uppercase shadow">
                          {m.name.charAt(0)}
                        </div>
                        <div>
                          <p className="font-semibold text-white">{m.name}</p>
                          <p className="text-slate-400 text-[11px]">{m.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4">
                      <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold border capitalize ${
                        m.role === 'owner'
                          ? 'bg-amber-500/10 text-amber-300 border-amber-500/30'
                          : m.role === 'admin'
                          ? 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30'
                          : 'bg-slate-800 text-slate-300 border-slate-700'
                      }`}>
                        {m.role === 'owner' ? <ShieldCheck className="w-3 h-3 text-amber-400" /> : <Shield className="w-3 h-3" />}
                        {m.role}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-slate-400">
                      {new Date(m.joined_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
