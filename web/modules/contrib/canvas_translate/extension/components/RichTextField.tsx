import { useEffect, useRef, useState } from 'react';
import { Box, Skeleton, Text, TextArea } from '@radix-ui/themes';

import { drupal, editorFormat, type CKEditor5Instance, type DrupalEditorFormat } from '../editorAssets.ts';

/** How long to wait for core's editor to come up before editing as HTML. */
const READY_TIMEOUT_MS = 15_000;

/**
 * Rich-text target editor: Drupal core's CKEditor 5 configured by the row's
 * text format — the same editor, plugins, toolbar and allowed markup as
 * Canvas.
 *
 * When the format has no CKEditor 5 editor or it fails to load, the field is
 * edited as HTML in a plain textarea instead.
 */
export function RichTextField({
  value,
  onChange,
  dir,
  format,
}: {
  value: string;
  onChange: (html: string) => void;
  dir: string;
  /** The row's text format machine name. */
  format: string;
}) {
  // Once a field falls back to HTML it keeps it, so a late editor never swaps
  // out the textarea mid-keystroke.
  const [html, setHtml] = useState(false);
  const [settings, setSettings] = useState<DrupalEditorFormat | null>(null);

  useEffect(() => {
    let active = true;
    editorFormat(format).then((loaded) => {
      if (!active) return;
      if (loaded) setSettings(loaded);
      else setHtml(true);
    });
    return () => {
      active = false;
    };
  }, [format]);

  if (html) {
    return <HtmlField value={value} onChange={onChange} dir={dir} />;
  }
  if (!settings) {
    return <Skeleton height="84px" />;
  }
  return (
    <CoreEditor value={value} onChange={onChange} dir={dir} settings={settings} onFailed={() => setHtml(true)} />
  );
}

/**
 * Hosts core's CKEditor 5 on a textarea React never renders into: core's
 * editor owns those DOM nodes, so React only owns the empty host element.
 */
function CoreEditor({
  value,
  onChange,
  dir,
  settings,
  onFailed,
}: {
  value: string;
  onChange: (html: string) => void;
  dir: string;
  settings: DrupalEditorFormat;
  onFailed: () => void;
}) {
  const hostRef = useRef<HTMLDivElement>(null);
  const editorRef = useRef<CKEditor5Instance | null>(null);
  // The value the editor holds, so a value coming back from our own onChange
  // is not loaded again.
  const shownRef = useRef<string | null>(null);
  // Set while the field loads a value itself, which is not an edit.
  const applyingRef = useRef(false);
  const [ready, setReady] = useState(false);
  const latest = useRef({ value, onChange, onFailed });
  latest.current = { value, onChange, onFailed };

  /** Loads a value into the editor. */
  const show = (editor: CKEditor5Instance, next: string): void => {
    applyingRef.current = true;
    try {
      editor.setData(next);
    } finally {
      applyingRef.current = false;
    }
    shownRef.current = next;
  };

  useEffect(() => {
    const host = hostRef.current!;
    const textarea = document.createElement('textarea');
    // Core sizes the editable area from `rows`.
    textarea.rows = 4;
    host.append(textarea);

    const { editors, CKEditor5Instances } = drupal();
    // Attached empty: the value is loaded once the editor is ready.
    editors.ckeditor5.attach(textarea, settings);

    let cancelled = false;
    let editor: CKEditor5Instance | null = null;
    const onData = () => {
      if (applyingRef.current || !editor) return;
      const data = editor.getData();
      shownRef.current = data;
      latest.current.onChange(data);
    };
    const started = Date.now();
    let timedOut = false;
    const waitForEditor = () => {
      const id = textarea.getAttribute('data-ckeditor5-id');
      const found = id ? CKEditor5Instances.get(id) ?? null : null;
      if (!found) {
        const elapsed = Date.now() - started;
        if (!timedOut && elapsed > READY_TIMEOUT_MS) {
          timedOut = true;
          if (!cancelled) latest.current.onFailed();
        }
        // Keep polling after the field gave up on the editor, so an editor
        // that still comes up is destroyed instead of leaking.
        if (elapsed < READY_TIMEOUT_MS * 4) window.setTimeout(waitForEditor, 25);
        return;
      }
      // Core's detach is a no-op until the editor exists, so an editor that
      // comes up after the field unmounted or gave up is destroyed here.
      if (cancelled || timedOut) {
        void editors.ckeditor5.detach(textarea, settings, 'unload');
        return;
      }
      editor = found;
      editorRef.current = editor;
      editor.model.document.on('change:data', onData);
      show(editor, latest.current.value);
      setReady(true);
    };
    waitForEditor();

    return () => {
      cancelled = true;
      editorRef.current = null;
      if (editor) {
        editor.model.document.off('change:data', onData);
        void editors.ckeditor5.detach(textarea, settings, 'unload');
      }
    };
    // `show` reads everything else through refs.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings]);

  // A value set from outside the editor (copy source, AI translation).
  useEffect(() => {
    const editor = editorRef.current;
    if (editor && value !== shownRef.current) {
      show(editor, value);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  return (
    <Box dir={dir} className="ct-rte" style={{ position: 'relative' }}>
      {!ready && <Skeleton height="84px" />}
      <div ref={hostRef} style={ready ? undefined : { display: 'none' }} />
    </Box>
  );
}

/** Fallback without an editor: the value as raw HTML, written back exactly as typed. */
function HtmlField({ value, onChange, dir }: { value: string; onChange: (html: string) => void; dir: string }) {
  return (
    <Box>
      <TextArea
        aria-label="Translation HTML"
        value={value}
        dir={dir}
        onChange={(e) => onChange(e.target.value)}
        rows={6}
        resize="vertical"
        style={{ fontFamily: 'var(--code-font-family)' }}
      />
      <Text as="p" size="1" color="gray" mt="1">
        The editor for this text format is not available, so it is edited as HTML.
      </Text>
    </Box>
  );
}
