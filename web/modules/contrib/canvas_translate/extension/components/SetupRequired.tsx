import { Box, Button, Card, Code, Flex, Heading, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';
import { basePath } from '../api.ts';

/**
 * Setup instructions shown when page translation cannot work at all — content
 * translation is not enabled for Canvas pages (or no translatable field is
 * left), so the backend refuses (409, code `translation_not_enabled`) and the
 * app hides the whole workspace behind this card. Individually
 * non-translatable fields do NOT land here; that is normal site configuration
 * and they are silently excluded while the workspace keeps working.
 */
export function SetupRequired({ onRetry }: { onRetry?: () => void }) {
  const settingsUrl = `${basePath()}admin/config/regional/content-language`;
  return (
    <Card size="3" style={{ maxWidth: 600 }}>
      <Flex direction="column" gap="3">
        <Flex align="center" gap="2">
          <ExclamationTriangleIcon width={20} height={20} style={{ color: 'var(--amber-9)', flexShrink: 0 }} />
          <Heading size="4">Translation isn’t enabled for pages yet</Heading>
        </Flex>
        <Text size="2" color="gray">
          Translating a page right now would overwrite its original language, so the translation
          workspace is hidden until translation is enabled for Canvas pages.
        </Text>
        <Box>
          <Text size="2" weight="bold">
            To enable it:
          </Text>
          <ol style={{ margin: 'var(--space-2) 0 0', paddingLeft: 'var(--space-5)', display: 'grid', gap: 'var(--space-1)' }}>
            <Text asChild size="2">
              <li>
                {/* The extension iframe sandbox blocks popups and top navigation, so a link here
                    does nothing. Show the path to copy until Canvas allows popups. */}
                Go to <Code>{settingsUrl}</Code> (Configuration → Regional and language → Content
                language and translation).
              </li>
            </Text>
            <Text asChild size="2">
              <li>
                Under <strong>Custom language settings</strong>, check <strong>Page</strong> and
                enable translation for the Page type.
              </li>
            </Text>
            <Text asChild size="2">
              <li>In the Page row, select the fields you want to be translated and save.</li>
            </Text>
          </ol>
        </Box>
        <Text size="1" color="gray">
          Changing these settings needs administration permissions. If the link above gives you
          “Access denied”, ask a site administrator to do this step.
        </Text>
        {onRetry && (
          <Box>
            <Button variant="soft" onClick={onRetry}>
              <ReloadIcon /> Check again
            </Button>
          </Box>
        )}
      </Flex>
    </Card>
  );
}
