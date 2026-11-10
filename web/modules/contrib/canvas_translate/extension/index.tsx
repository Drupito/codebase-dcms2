import { createRoot } from 'react-dom/client';
import { Theme } from '@radix-ui/themes';
import '@radix-ui/themes/styles.css';
import App from './App.tsx';

const root = document.getElementById('root');
if (root) {
  createRoot(root).render(
    // Mirror Canvas's own Theme (accent blue, gray neutrals, light, solid
    // panels) so the extension is visually part of the same product.
    <Theme
      accentColor="blue"
      grayColor="slate"
      appearance="light"
      panelBackground="solid"
      radius="medium"
      style={{ height: '100vh' }}
    >
      <App />
    </Theme>,
  );
}
