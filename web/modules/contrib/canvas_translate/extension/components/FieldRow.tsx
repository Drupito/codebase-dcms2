import { Box, Button, Flex, IconButton, Text, TextArea, Tooltip } from '@radix-ui/themes';
import { CheckIcon, MagicWandIcon, Pencil1Icon, PinBottomIcon } from '@radix-ui/react-icons';
import type { FieldRowData } from '../types.ts';
import { RichTextField } from './RichTextField.tsx';
import { wordDiff, type DiffSeg } from '../diff.ts';

/** Eyebrow label above each field — sentence case, not all-caps. */
function FieldLabel({ children }: { children: React.ReactNode }) {
  return (
    <Text size="1" weight="medium" color="gray" mb="1" style={{ display: 'block' }}>
      {children}
    </Text>
  );
}

/** Renders a word diff inline: removed words struck red, added words green. */
export function SourceDiff({ oldText, newText, dir }: { oldText: string; newText: string; dir: string }) {
  return (
    <Text size="1" dir={dir} style={{ display: 'block', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
      {wordDiff(oldText, newText).map((seg, idx) =>
        seg.type === 'same' ? (
          <span key={idx} style={{ color: 'var(--gray-12)' }}>{seg.text}</span>
        ) : seg.type === 'del' ? (
          <span key={idx} style={{ color: 'var(--red-11)', background: 'var(--red-3)', textDecoration: 'line-through' }}>{seg.text}</span>
        ) : (
          <span key={idx} style={{ color: 'var(--green-11)', background: 'var(--green-3)' }}>{seg.text}</span>
        ),
      )}
    </Text>
  );
}

export function FieldRow({
  row,
  value,
  onChange,
  registerNav,
  sourceDir,
  targetDir,
  onAi,
  aiBusy = false,
  aiDisabled = false,
  reviewMode = false,
  reviewed = false,
  onKeep,
  onUpdate,
}: {
  row: FieldRowData;
  value: string;
  onChange: (value: string) => void;
  /** Registers this row's wrapper element for jump-to-next navigation. */
  registerNav?: (el: HTMLElement | null) => void;
  sourceDir: string;
  targetDir: string;
  /** Translate this single row via AI; omitted when AI is unavailable. */
  onAi?: () => void;
  /** This row's AI translation is in flight. */
  aiBusy?: boolean;
  /** Some AI translation (this row or another) is in flight. */
  aiDisabled?: boolean;
  /** Guided review is active — show Keep/Update on a changed field. */
  reviewMode?: boolean;
  /** This changed field has been acknowledged in the current review pass. */
  reviewed?: boolean;
  /** Keep the existing translation for this changed field (no edit needed). */
  onKeep?: () => void;
  /** Edit the translation for this changed field. */
  onUpdate?: () => void;
}) {
  const isRich = row.format !== null;
  const empty = value.trim() === '';
  // A field in a text format the user may not use can be read, not edited.
  const hasSource = row.source.trim() !== '' && !row.readOnly;
  // Size both columns from the same line estimate so each row's source and
  // target stay the same height.
  const rows = Math.min(8, Math.max(2, Math.ceil(Math.max(row.source.length, value.length) / 60)));

  return (
    <Box ref={registerNav} py="3" className="ct-field" style={{ borderTop: '1px solid var(--gray-4)' }}>
      {/* Source — read-only origin text. */}
      <Box>
        <FieldLabel>{row.label}</FieldLabel>
        {/* Outdated drift: show what the source said when this was last
            translated, so the translator fixes only what changed. */}
        {row.sourceWas !== undefined && (
          <Box
            mb="1"
            p="2"
            style={{
              background: 'var(--amber-2)',
              border: '1px solid var(--amber-6)',
              borderRadius: 'var(--radius-3)',
            }}
          >
            <Text size="1" weight="medium" mb="1" style={{ display: 'block', color: 'var(--amber-12)' }}>
              Source changed since last translation
            </Text>
            {isRich ? (
              // Rich text is HTML; word-diffing raw markup would corrupt tags, so
              // show the previous source verbatim. ponytail: upgrade to an
              // HTML-aware diff only if translators ask for it.
              <Box
                dir={sourceDir}
                style={{ color: 'var(--gray-12)', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}
                dangerouslySetInnerHTML={{ __html: row.sourceWas || '<em>Empty</em>' }}
              />
            ) : (
              <SourceDiff oldText={row.sourceWas} newText={row.source} dir={sourceDir} />
            )}
            {/* In guided review, decide this field in place: keep the existing
                translation or jump in to edit it. */}
            {reviewMode && (
              <Flex align="center" gap="2" mt="2">
                <Button size="1" variant="soft" color="amber" onClick={onKeep}>
                  <CheckIcon />
                  Keep translation
                </Button>
                <Button size="1" variant="soft" color="gray" onClick={onUpdate}>
                  <Pencil1Icon />
                  Update
                </Button>
                {reviewed && (
                  <Flex align="center" gap="1" style={{ color: 'var(--green-11)' }}>
                    <CheckIcon width="13" height="13" />
                    <Text size="1" weight="medium">Reviewed</Text>
                  </Flex>
                )}
              </Flex>
            )}
          </Box>
        )}
        {isRich ? (
          <Box
            dir={sourceDir}
            p="2"
            style={{
              minHeight: 60,
              color: 'var(--gray-11)',
              background: 'var(--color-surface)',
              border: '1px solid var(--gray-7)',
              borderRadius: 'var(--radius-3)',
              whiteSpace: 'pre-wrap',
              wordBreak: 'break-word',
            }}
            dangerouslySetInnerHTML={{ __html: row.source || '<em>Empty</em>' }}
          />
        ) : (
          <TextArea
            readOnly
            tabIndex={-1}
            value={row.source}
            dir={sourceDir}
            rows={rows}
            variant="surface"
            color="gray"
            resize="none"
            placeholder="Empty"
          />
        )}
      </Box>

      {/* Target — the active translation surface. */}
      <Box>
        <Flex justify="between" align="center" mb="1" style={{ minHeight: 20 }}>
          <Flex align="center" gap="2">
            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: empty ? 'var(--gray-8)' : 'var(--accent-9)' }} />
            <Text size="1" weight="medium" color="gray">{empty ? 'Untranslated' : 'Translation'}</Text>
          </Flex>
          <Flex gap="2" className="ct-field__actions">
            {onAi && hasSource && (
              <Tooltip content="Translate this field with AI">
                <IconButton size="1" variant="ghost" onClick={onAi} disabled={aiDisabled} loading={aiBusy}>
                  <MagicWandIcon />
                </IconButton>
              </Tooltip>
            )}
            {hasSource && (
              <Tooltip content="Copy source text into this field">
                <IconButton size="1" variant="ghost" color="gray" onClick={() => onChange(row.source)}>
                  <PinBottomIcon />
                </IconButton>
              </Tooltip>
            )}
          </Flex>
        </Flex>
        {row.readOnly ? (
          <Box>
            <Box
              dir={targetDir}
              p="2"
              style={{
                minHeight: 60,
                color: 'var(--gray-11)',
                background: 'var(--gray-2)',
                border: '1px solid var(--gray-6)',
                borderRadius: 'var(--radius-3)',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
              }}
              dangerouslySetInnerHTML={{ __html: value || '<em>Empty</em>' }}
            />
            <Text as="p" size="1" color="gray" mt="1">
              You do not have access to this field's text format, so it cannot be translated here.
            </Text>
          </Box>
        ) : isRich ? (
          <RichTextField value={value} onChange={onChange} dir={targetDir} format={row.format!} />
        ) : (
          <TextArea
            value={value}
            dir={targetDir}
            onChange={(e) => onChange(e.target.value)}
            placeholder="Add translation…"
            rows={rows}
            resize="vertical"
            color={empty ? 'gray' : undefined}
          />
        )}
      </Box>
    </Box>
  );
}
