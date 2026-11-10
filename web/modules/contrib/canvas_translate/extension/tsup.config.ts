import { defineConfig } from 'tsup';

export default defineConfig((options) => {
  const nodeEnv = options.watch ? 'development' : 'production';
  return {
    entry: ['index.tsx'],
    format: ['iife'],
    globalName: 'canvasTranslate',
    clean: true,
    minify: !options.watch,
    env: {
      NODE_ENV: nodeEnv,
    },
    // CJS deps (react-dom, ckeditor builds) reference `process` at runtime.
    banner: {
      js: `var process = { env: { NODE_ENV: ${JSON.stringify(nodeEnv)} } };`,
    },
  };
});
