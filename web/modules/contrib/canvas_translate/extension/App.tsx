import { HashRouter, Route, Routes, useLocation } from 'react-router-dom';
import { useCallback, useEffect, useState } from 'react';
import { Flex, Spinner } from '@radix-ui/themes';
import { fetchItems } from './api.ts';
import Dashboard from './components/Dashboard.tsx';
import PageEditor from './components/PageEditor.tsx';
import PageOverview from './components/PageOverview.tsx';
import ReviewPanel from './components/ReviewPanel.tsx';
import ReviewDiff from './components/ReviewDiff.tsx';
import { SetupRequired } from './components/SetupRequired.tsx';

/**
 * Mirror the in-app hash route to the parent Canvas URL, so the browser back
 * button and deep links work for the page extension.
 *
 * @see Canvas ExtensionPage `canvas:navigate` handling.
 */
function NavigationBridge() {
  const location = useLocation();
  useEffect(() => {
    const subPath = `${location.pathname}${location.search}`.replace(/^\//, '');
    window.parent.postMessage({ type: 'canvas:navigate', subPath }, window.location.origin);
  }, [location.pathname, location.search]);
  return null;
}

export default function App() {
  // Workspace-wide setup gate: while page translation cannot work at all
  // (dashboard payload `translationBlocked` — content translation not enabled
  // for canvas_page), every translation surface is hidden and only the setup
  // instructions render — a half-working workspace invites edits that can't
  // publish. Individually non-translatable fields do NOT block; that is
  // normal site configuration and they are silently excluded. On a fetch
  // failure the gate opens and each screen reports its own error as before.
  const [blocked, setBlocked] = useState(false);
  const [checking, setChecking] = useState(true);
  const check = useCallback(() => {
    setChecking(true);
    fetchItems()
      .then((res) => setBlocked(res.translationBlocked ?? false))
      .catch(() => setBlocked(false))
      .finally(() => setChecking(false));
  }, []);
  useEffect(check, [check]);

  if (checking) {
    return (
      <Flex align="center" justify="center" style={{ height: '100vh' }}>
        <Spinner />
      </Flex>
    );
  }
  if (blocked) {
    return (
      <Flex align="center" justify="center" p="4" style={{ height: '100vh', background: 'var(--color-background)' }}>
        <SetupRequired onRetry={check} />
      </Flex>
    );
  }

  return (
    <HashRouter>
      <NavigationBridge />
      <div style={{ display: 'flex', flexDirection: 'column', height: '100vh', background: 'var(--color-background)' }}>
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/review" element={<ReviewPanel />} />
          <Route path="/review/page/:id/:langcode" element={<ReviewDiff />} />
          <Route path="/review/config/:type/:id/:langcode" element={<ReviewDiff />} />
          <Route path="/page/:id" element={<PageOverview />} />
          <Route path="/page/:id/:langcode" element={<PageEditor />} />
          <Route path="/config/:type/:id/:langcode" element={<PageEditor />} />
        </Routes>
      </div>
    </HashRouter>
  );
}
